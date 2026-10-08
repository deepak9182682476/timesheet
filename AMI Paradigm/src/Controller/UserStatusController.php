<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Users are never deleted, only disabled (see UserVoter, "delete").
 * Disabling needs a reason, which is kept with the user, and takes the person off every project they
 * are mapped to (all their assignments on the "Project mapping" page). Their time entries stay as they are.
 */
#[Route(path: '/admin/user')]
final class UserStatusController extends AbstractController
{
    public const PREF_REASON = 'ami_disabled_reason';
    public const PREF_WHEN = 'ami_disabled_at';
    public const PREF_BY = 'ami_disabled_by';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route(path: '/{id}/disable', name: 'admin_user_disable', methods: ['GET', 'POST'])]
    public function disable(User $person, Request $request): Response
    {
        $this->denyUnlessAllowed($person);
        if (!$person->isEnabled()) {
            $this->flashError($person->getDisplayName() . ' is disabled already.');

            return $this->redirectToRoute('admin_user');
        }

        $mapped = $this->getMappedProjects($person);

        $form = $this->createFormBuilder()
            ->add('reason', TextareaType::class, [
                'label' => 'Reason',
                'translation_domain' => false,
                'help' => 'Why is this person disabled? For example: left the company on 30 Sep 2026.',
                'attr' => ['rows' => 3, 'maxlength' => 500, 'autofocus' => 'autofocus'],
                'constraints' => [new NotBlank(message: 'Please enter the reason.'), new Length(min: 3, max: 500)],
            ])
            ->setAction($this->generateUrl('admin_user_disable', ['id' => $person->getId()]))
            ->setMethod('POST')
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            try {
                $person->setEnabled(false);
                $person->setPreferenceValue(self::PREF_REASON, trim((string) $form->get('reason')->getData()));
                $person->setPreferenceValue(self::PREF_WHEN, (new \DateTimeImmutable())->format('Y-m-d H:i'));
                $person->setPreferenceValue(self::PREF_BY, $this->getUser()->getDisplayName());
                // users are saved only when persisted explicitly (DEFERRED_EXPLICIT), their preferences with them
                $this->entityManager->persist($person);
                foreach ([self::PREF_REASON, self::PREF_WHEN, self::PREF_BY] as $name) {
                    $preference = $person->getPreference($name);
                    if ($preference !== null) {
                        $this->entityManager->persist($preference);
                    }
                }
                $this->entityManager->flush();

                // off every item of every project (Epic, Feature, ... Task)
                $removed = (int) $connection->executeStatement('DELETE FROM kimai2_work_item_users WHERE user_id = ?', [(int) $person->getId()]);
                $connection->commit();
            } catch (\Throwable $ex) {
                $connection->rollBack();
                throw $ex;
            }

            $message = $person->getDisplayName() . ' is disabled.';
            if ($removed > 0) {
                $message .= ' Removed from ' . \count($mapped) . (\count($mapped) === 1 ? ' project' : ' projects') . ' (' . implode(', ', $mapped) . '): ' . $removed . ($removed === 1 ? ' mapped item.' : ' mapped items.');
            } else {
                $message .= ' The person was not mapped to any project.';
            }
            // success messages are not shown in this app, so this one is an info message
            $this->addFlash('info', $message);

            return $this->redirectToRoute('admin_user');
        }

        return $this->render('user-status/disable.html.twig', [
            'person' => $person,
            'mapped' => $mapped,
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/{id}/enable', name: 'admin_user_enable', methods: ['GET', 'POST'])]
    public function enable(User $person, Request $request): Response
    {
        $this->denyUnlessAllowed($person);
        if ($person->isEnabled()) {
            return $this->redirectToRoute('admin_user');
        }

        $form = $this->createFormBuilder()
            ->setAction($this->generateUrl('admin_user_enable', ['id' => $person->getId()]))
            ->setMethod('POST')
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $person->setEnabled(true);
            $this->entityManager->persist($person);
            $this->entityManager->flush();
            $this->addFlash('info', $person->getDisplayName() . ' is enabled again. Map the person to their projects on the "Project mapping" page.');

            return $this->redirectToRoute('admin_user');
        }

        return $this->render('user-status/enable.html.twig', [
            'person' => $person,
            'reason' => $person->getPreferenceValue(self::PREF_REASON),
            'when' => $person->getPreferenceValue(self::PREF_WHEN),
            'by' => $person->getPreferenceValue(self::PREF_BY),
            'form' => $form->createView(),
        ]);
    }

    private function denyUnlessAllowed(User $person): void
    {
        // the same people who could switch "Active" on the profile before; nobody can disable themselves
        if ($person->getId() === $this->getUser()->getId() || !$this->isGranted('edit', $person)) {
            throw $this->createAccessDeniedException('You cannot change this user.');
        }
    }

    /**
     * @return array<string> the names of the projects the person is mapped to
     */
    private function getMappedProjects(User $person): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT p.name FROM kimai2_work_item_users u
             JOIN kimai2_work_items i ON i.id = u.work_item_id
             JOIN kimai2_projects p ON p.id = i.project_id
             WHERE u.user_id = ? ORDER BY p.name',
            [(int) $person->getId()]
        );
    }
}
