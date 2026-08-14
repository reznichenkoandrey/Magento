<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * Turns configuration into `FamilyDefinition`s, and is the only place that decides whether a family
 * is runnable.
 *
 * "Enabled" is not enough on its own: a family with no group attribute has no key to group by, and a
 * family whose distinct-variants switch is on but has no variant attribute has nothing to be
 * distinct about. Rather than let those combinations reach the pipeline and produce a confusing
 * empty result, they are refused here, once, with a reason the CLI and the cron can both print.
 */
class FamilyDefinitionPool
{
    public function __construct(
        private readonly Config $config,
        private readonly FamilyLinkType $linkTypes
    ) {
    }

    /**
     * @return string[]
     */
    public function getFamilyCodes(): array
    {
        return $this->linkTypes->getFamilyCodes();
    }

    public function has(string $familyCode): bool
    {
        return $this->linkTypes->isFamilyCode($familyCode);
    }

    /**
     * @return FamilyDefinition[] keyed by family code, in render order
     */
    public function getRunnable(): array
    {
        $definitions = [];
        foreach ($this->linkTypes->getFamilyCodes() as $familyCode) {
            $definition = $this->get($familyCode);
            if ($definition !== null) {
                $definitions[$familyCode] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return FamilyDefinition|null null when the family is switched off or incompletely configured
     */
    public function get(string $familyCode): ?FamilyDefinition
    {
        if ($this->getRefusalReason($familyCode) !== null) {
            return null;
        }

        return new FamilyDefinition(
            $familyCode,
            $this->linkTypes->getLinkTypeId($familyCode),
            $this->config->getGroupAttribute($familyCode),
            $this->config->getVariantAttribute($familyCode),
            $this->config->getMaxMembers($familyCode),
            $this->config->isDistinctVariants($familyCode) && $this->config->getVariantAttribute($familyCode) !== '',
            $this->config->getLabel($familyCode)
        );
    }

    /**
     * @return string|null null when the family is runnable
     */
    public function getRefusalReason(string $familyCode): ?string
    {
        if (!$this->linkTypes->isFamilyCode($familyCode)) {
            return sprintf('unknown family "%s"', $familyCode);
        }

        if (!$this->config->isEnabled()) {
            return 'the module is switched off';
        }

        if (!$this->config->isFamilyEnabled($familyCode)) {
            return sprintf('family "%s" is switched off', $familyCode);
        }

        if ($this->config->getGroupAttribute($familyCode) === '') {
            return sprintf('family "%s" has no group attribute configured', $familyCode);
        }

        return null;
    }
}
