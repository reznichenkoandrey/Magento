<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;
use Scr1be\ProductFamilies\Api\FamilyReconcilerInterface;
use Scr1be\ProductFamilies\Api\ReconcileProgressInterface;
use Scr1be\ProductFamilies\Model\ResourceModel\LinkWriter;
use Scr1be\ProductFamilies\Model\ResourceModel\OptionSortOrder;
use Scr1be\ProductFamilies\Model\ResourceModel\ProductScanner;

/**
 * The pipeline, assembled.
 *
 * scan → group → order → cap → diff → write → invalidate. Every stage is a class that knows nothing
 * about the ones on either side of it, which is what makes the middle four testable without a
 * database and the outer two replaceable without touching the middle.
 *
 * @see FamilyReconcilerInterface
 */
class Reconciler implements FamilyReconcilerInterface
{
    public function __construct(
        private readonly FamilyDefinitionPool $definitions,
        private readonly FamilyLinkType $linkTypes,
        private readonly ProductScanner $scanner,
        private readonly OptionSortOrder $optionSortOrder,
        private readonly Grouper $grouper,
        private readonly PositionResolver $positionResolver,
        private readonly FamilyCapper $capper,
        private readonly LinkPlanner $planner,
        private readonly LinkWriter $writer,
        private readonly CacheInvalidator $cacheInvalidator
    ) {
    }

    public function reconcile(
        string $familyCode,
        bool $dryRun = false,
        ?ReconcileProgressInterface $progress = null
    ): ReconcileResultInterface {
        $refusalReason = $this->definitions->getRefusalReason($familyCode);
        if ($refusalReason !== null) {
            return ReconcileResult::refused(
                $familyCode,
                $this->linkTypes->isFamilyCode($familyCode) ? $this->linkTypes->getLinkTypeId($familyCode) : 0,
                $refusalReason
            );
        }

        /** @var FamilyDefinition $definition — non-null, the refusal check above is exhaustive */
        $definition = $this->definitions->get($familyCode);

        $families = $this->grouper->dropSingletons(
            $this->grouper->group(
                $this->scanner->scan($definition->getGroupAttribute(), $definition->getVariantAttribute())
            )
        );

        $ranking = $this->optionSortOrder->getRanking($definition->getVariantAttribute());

        $progress?->start(count($families));

        $desired = [];
        $memberships = 0;

        foreach ($families as $members) {
            $ordered = $this->positionResolver->resolve($members, $ranking);
            if ($definition->isDistinctVariants()) {
                $ordered = $this->capper->collapseDuplicateVariants($ordered);
            }

            // Counts memberships rather than products: a multiselect family key puts one product in
            // several families, and the number worth reporting is how much work the run did.
            $memberships += count($ordered);

            foreach ($this->capper->buildLinks($ordered, $definition->getMaxMembers()) as $productId => $links) {
                foreach ($links as $linkedProductId => $position) {
                    // The same pair can be produced by two families when the group attribute is a
                    // multiselect. There is one link row for the pair, so one of the two positions
                    // has to win: the lower one, which puts the pair at the front of the row it is
                    // most prominent in and — unlike "last writer wins" — does not depend on the
                    // order the families came out of the grouper.
                    $known = $desired[$productId][$linkedProductId] ?? null;
                    if ($known === null || $known > $position) {
                        $desired[$productId][$linkedProductId] = $position;
                    }
                }
            }

            $progress?->advance();
        }

        $progress?->finish();

        $plan = $this->planner->plan($desired, $this->writer->readCurrent($definition->getLinkTypeId()));

        if (!$dryRun && !$plan->isEmpty()) {
            $this->writer->apply($plan, $definition->getLinkTypeId());
            $this->cacheInvalidator->invalidateProducts($plan->getAffectedProductIds());
        }

        return ReconcileResult::fromPlan(
            $familyCode,
            $definition->getLinkTypeId(),
            count($families),
            $memberships,
            $plan,
            $dryRun
        );
    }
}
