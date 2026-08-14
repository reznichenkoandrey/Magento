/**
 * The HTTP half of the popup: form-encoding, the form key, and turning a failed request into a value
 * rather than a rejected promise.
 *
 * It is its own module because it is the only part of the frontend that knows what shape the PHP
 * controllers expect — `alert_ids[]` as a repeated field, `qty[<alert id>]` as a map, `form_key`
 * always — and those are contracts with code in another language that nothing else would catch
 * drifting.
 */

/** What `Magento\Framework\App\Request\Http` parses into `$_POST` array parameters. */
export const CONTENT_TYPE = 'application/x-www-form-urlencoded; charset=UTF-8';

/**
 * PHP reads `a[]=1&a[]=2` as a list and `q[7]=2` as a map, which is exactly what
 * `JsonPostAction::readAlertIds()` and `AddToCart::resolveQty()` are written against.
 *
 * @param {Object} params
 * @returns {string}
 */
export const encodeParams = (params) => {
    const body = new URLSearchParams();

    Object.keys(params).forEach((key) => {
        const value = params[key];

        if (Array.isArray(value)) {
            value.forEach((entry) => body.append(`${key}[]`, String(entry)));
            return;
        }

        if (value !== null && typeof value === 'object') {
            Object.keys(value).forEach((inner) => body.append(`${key}[${inner}]`, String(value[inner])));
            return;
        }

        if (value !== undefined && value !== null) {
            body.append(key, String(value));
        }
    });

    return body.toString();
};

/**
 * Hyvä sets and reads the `form_key` cookie itself, generating one if the visitor arrived without.
 * Reading it through `hyva.getFormKey()` rather than off a hidden input is what lets the popup live
 * in a full-page-cached document with no form in it.
 *
 * @param {Window} win
 * @returns {string}
 */
const readFormKey = (win) => (
    win.hyva && typeof win.hyva.getFormKey === 'function' ? win.hyva.getFormKey() : ''
);

/**
 * @param {Window} win
 * @returns {function(string, Object=): Promise<Object>}
 */
export const createClient = (win) => async (url, params = {}) => {
    let response;

    try {
        response = await win.fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': CONTENT_TYPE },
            credentials: 'include',
            body: encodeParams({ ...params, form_key: readFormKey(win) }),
        });
    } catch (error) {
        // An offline tab or a dropped connection. The caller's job is to leave the popup as it was,
        // not to handle an exception on every button.
        return { success: false, status: 0 };
    }

    // 401 and 403 both arrive here: a session that expired behind the cached page, and a form key
    // that did. Neither is an exception, and both mean "nothing changed on the server", which is
    // what `success: false` tells the caller.
    try {
        const body = await response.json();

        return { ...body, status: response.status, success: response.ok && body.success !== false };
    } catch (error) {
        return { success: false, status: response.status };
    }
};
