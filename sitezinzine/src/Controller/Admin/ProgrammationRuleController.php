<?php

namespace App\Controller\Admin;

use App\Entity\ProgrammationRule;
use App\Form\ProgrammationRuleType;
use App\Repository\ProgrammationRuleRepository;
use App\Service\ProgrammationRuleConflictChecker;
use App\Service\ProgrammationRuleMutationInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/programmationRule', name: 'admin_programmationRule_')]
#[IsGranted('ROLE_ADMIN')]
class ProgrammationRuleController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        ProgrammationRuleRepository $repository,
        ProgrammationRuleConflictChecker $conflictChecker
    ): Response {
        $rules = $repository->findAllNotDeleted();
        $conflictStates = [];

        foreach ($rules as $rule) {
            if ($rule->isActive()) {
                continue;
            }

            $conflictStates[$rule->getId()] =
                $conflictChecker->hasRuleStructuralConflicts($rule);
        }

        return $this->render(
            'admin/programmationRule/index.html.twig',
            [
                'rules' => $rules,
                'conflictStates' => $conflictStates,
            ]
        );
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ProgrammationRuleRepository $programmationRuleRepository
    ): Response {
        $programmationRule = new ProgrammationRule();

        $form = $this->createForm(
            ProgrammationRuleType::class,
            $programmationRule
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = $programmationRule->getCategory();

            if ($category === null) {
                $this->addFlash(
                    'danger',
                    'La catégorie est obligatoire.'
                );

                return $this->render(
                    'admin/programmationRule/create.html.twig',
                    [
                        'form' => $form,
                        'rule' => $programmationRule,
                    ]
                );
            }

            if ($programmationRule->getRuleNumber() === null) {
                $maxRuleNumber =
                    $programmationRuleRepository
                    ->findMaxRuleNumberByCategory($category);

                $programmationRule->setRuleNumber(
                    $maxRuleNumber + 1
                );
            }

            $em->persist($programmationRule);
            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Règle créée avec succès : %s.',
                    $programmationRule->getDisplayName()
                )
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        return $this->render(
            'admin/programmationRule/create.html.twig',
            [
                'form' => $form,
                'rule' => $programmationRule,
            ]
        );
    }

    #[Route(
        '/{id}/edit',
        name: 'edit',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+']
    )]
    public function edit(
        Request $request,
        ProgrammationRule $programmationRule,
        ProgrammationRuleConflictChecker $conflictChecker,
        ProgrammationRuleMutationInterface $mutationService
    ): Response {
        if ($programmationRule->isDeleted()) {
            $this->addFlash(
                'danger',
                'Règle supprimée.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        $form = $this->createForm(
            ProgrammationRuleType::class,
            $programmationRule
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hasConflict =
                $conflictChecker
                ->hasRuleStructuralConflicts(
                    $programmationRule
                );

            /*
         * Une désactivation automatique provoquée par un conflit
         * n'est PAS une invalidation explicite de la règle.
         *
         * La nouvelle structure est enregistrée et les affectations
         * futures sont simplement synchronisées avec elle.
         */
            if (
                $hasConflict
                && $programmationRule->isActive()
            ) {
                $programmationRule->setIsActive(false);
            }

            /*
         * Une modification de la règle peut modifier sa période
         * de validité et donc la structure de ses occurrences.
         *
         * L'orchestrateur :
         * - détecte les semaines publiées impactées ;
         * - les dévalide si nécessaire ;
         * - synchronise les groupes encore modifiables ;
         * - conserve entièrement les groupes historiques.
         */
            $mutationService->synchronizeAfterStructuralChange(
                $programmationRule
            );

            if ($hasConflict) {
                $this->addFlash(
                    'warning',
                    sprintf(
                        'Règle mise à jour : %s. Elle a été désactivée car elle présente un conflit de programmation.',
                        $programmationRule->getDisplayName()
                    )
                );
            } elseif (!$programmationRule->isActive()) {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Règle mise à jour : %s. Elle ne présente plus de conflit et peut être réactivée.',
                        $programmationRule->getDisplayName()
                    )
                );
            } else {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Règle mise à jour : %s.',
                        $programmationRule->getDisplayName()
                    )
                );
            }

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        return $this->render(
            'admin/programmationRule/edit.html.twig',
            [
                'form' => $form,
                'rule' => $programmationRule,
            ]
        );
    }

    #[Route(
        '/{id}/activate',
        name: 'activate',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function activate(
        Request $request,
        ProgrammationRule $programmationRule,
        EntityManagerInterface $em,
        ProgrammationRuleConflictChecker $conflictChecker
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'activate_rule_' . $programmationRule->getId(),
                (string) $request->request->get('_token')
            )
        ) {
            $this->addFlash(
                'danger',
                'Jeton CSRF invalide.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        if ($programmationRule->isDeleted()) {
            $this->addFlash(
                'danger',
                'Une règle supprimée ne peut pas être réactivée.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        if ($programmationRule->isActive()) {
            $this->addFlash(
                'warning',
                'Cette règle est déjà active.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        if (
            !$conflictChecker->canActivateRule(
                $programmationRule
            )
        ) {
            $this->addFlash(
                'danger',
                'Cette règle ne peut pas être réactivée tant qu’elle présente un conflit de programmation.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        $programmationRule->setIsActive(true);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Règle réactivée : %s.',
                $programmationRule->getDisplayName()
            )
        );

        return $this->redirectToRoute(
            'admin_programmationRule_index'
        );
    }

    #[Route(
        '/{id}/deactivate',
        name: 'deactivate',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function deactivate(
        Request $request,
        ProgrammationRule $programmationRule,
        ProgrammationRuleMutationInterface $mutationService
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'deactivate_rule_' . $programmationRule->getId(),
                (string) $request->request->get('_token')
            )
        ) {
            $this->addFlash(
                'danger',
                'Jeton CSRF invalide.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        if ($programmationRule->isDeleted()) {
            $this->addFlash(
                'danger',
                'Une règle supprimée ne peut pas être désactivée.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        if (!$programmationRule->isActive()) {
            $this->addFlash(
                'warning',
                'Cette règle est déjà inactive.'
            );

            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        /*
     * Une désactivation manuelle constitue une décision structurelle
     * explicite : les groupes futurs de cette règle doivent disparaître.
     *
     * L'orchestrateur dévalide d'abord toutes les semaines publiées
     * concernées, puis invalide les groupes encore modifiables.
     *
     * Les groupes dont la première Diffusion publiée est déjà passée
     * restent entièrement gelés.
     */
        $programmationRule->setIsActive(false);

        $mutationService->invalidateRule(
            $programmationRule
        );

        $this->addFlash(
            'success',
            sprintf(
                'Règle désactivée : %s.',
                $programmationRule->getDisplayName()
            )
        );

        return $this->redirectToRoute(
            'admin_programmationRule_index'
        );
    }

    #[Route(
        '/{id}/delete',
        name: 'delete',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function delete(
        Request $request,
        ProgrammationRule $programmationRule,
        ProgrammationRuleMutationInterface $mutationService
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'delete_rule_' . $programmationRule->getId(),
                (string) $request->request->get('_token')
            )
        ) {
            return $this->redirectToRoute(
                'admin_programmationRule_index'
            );
        }

        /*
     * La suppression d'une règle constitue elle aussi une décision
     * structurelle explicite.
     *
     * Le soft delete est appliqué avant l'orchestration afin que
     * l'état final de la règle fasse partie de la même opération.
     *
     * Les semaines futures déjà publiées sont dévalidées avant
     * l'invalidation des groupes. Les groupes historiques restent gelés.
     */
        $programmationRule->softDelete();

        $mutationService->invalidateRule(
            $programmationRule
        );

        $this->addFlash(
            'success',
            sprintf(
                'Règle supprimée : %s.',
                $programmationRule->getDisplayName()
            )
        );

        return $this->redirectToRoute(
            'admin_programmationRule_index'
        );
    }
}
