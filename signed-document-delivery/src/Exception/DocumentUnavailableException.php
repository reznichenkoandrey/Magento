<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The one failure the caller is ever told about.
 *
 * "No such document", "that document belongs to somebody else" and "that document belongs to
 * another store view" are three different facts on the server and one message on the wire. Keeping
 * them apart in the response would turn the mutation into an oracle: a client could walk the
 * invoice id space and learn which ids exist by watching the error text change.
 *
 * The reason is not lost — it is passed to the logger at the point it is raised, where it is useful
 * to an operator and useless to an attacker.
 */
class DocumentUnavailableException extends LocalizedException
{
}
