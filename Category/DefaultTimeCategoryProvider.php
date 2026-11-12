<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Category;

use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;

/**
 * Two categories: absence (the projects picked under System > Settings > MCP) and everything else. A person's absence
 * project is the absence project they last logged time on.
 */
final class DefaultTimeCategoryProvider implements TimeCategoryProviderInterface
{
    public const OTHER = 'other';

    public function __construct(
        private readonly McpSettings $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getCategories(): array
    {
        return [self::ABSENCE => 'Absence', self::OTHER => 'Other time'];
    }

    public function categoryFor(?int $projectId, ?int $activityId, ?int $customerId): string
    {
        return $projectId !== null && \in_array($projectId, $this->getAbsenceProjectIds(), true) ? self::ABSENCE : self::OTHER;
    }

    public function getAbsenceProjectIds(): array
    {
        return $this->settings->absenceProjectIds();
    }

    public function getAbsenceProjectFor(User $user): ?Project
    {
        $ids = $this->getAbsenceProjectIds();
        if ($ids === []) {
            return null;
        }
        // with a single absence project there is nothing to choose, even for someone who has never logged absence
        if (\count($ids) === 1) {
            return $this->entityManager->find(Project::class, $ids[0]);
        }

        /** @var list<Timesheet> $latest */
        $latest = $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(Timesheet::class, 't')
            ->join('t.project', 'p')
            ->where('t.user = :user')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
            ->orderBy('t.begin', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return ($latest[0] ?? null)?->getProject();
    }
}
