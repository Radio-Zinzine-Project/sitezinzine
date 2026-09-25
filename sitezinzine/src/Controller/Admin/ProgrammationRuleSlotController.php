<?php

namespace App\Controller\Admin;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Form\ProgrammationRuleSlotType;
use App\Repository\ProgrammationRuleSlotRepository;
use App\Repository\ProgrammationRuleRepository;
use App\Service\ProgrammationRuleMutationInterface;
use App\Service\ProgrammationRuleConflictChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/programmationRule/{ruleId}/slots', name: 'admin_programmationRuleSlot_')]
#[IsGranted('ROLE_ADMIN')]
class ProgrammationRuleSlotController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        int $ruleId,
        ProgrammationRuleRepository $programmationRuleRepository,
        ProgrammationRuleSlotRepository $slotRepository,
        ProgrammationRuleConflictChecker $conflictChecker
    ): Response {
        $programmationRule = $programmationRuleRepository->find($ruleId);

        if (
            !$programmationRule
            || $programmationRule->isDeleted()
        ) {
            throw $this->createNotFoundException(
                'Règle de programmation introuvable.'
            );
        }

        $slots = $slotRepository->findNotDeletedByRule(
            $programmationRule
        );

        $conflictsBySlotId = [];

        foreach (
            $conflictChecker->findRuleStructuralConflicts(
                $programmationRule
            )
            as $conflict
        ) {
            $candidate = $conflict['candidate'];
            $conflicting = $conflict['conflicting'];

            $candidateId = $candidate->getId();
            $conflictingId = $conflicting->getId();

            if ($candidateId !== null) {
                $conflictsBySlotId[$candidateId][] = $conflicting;
            }

            /*
             * Si le créneau opposé appartient lui aussi à la règle
             * actuellement affichée, il doit également être signalé.
             *
             * Pour un conflit avec une autre règle, on ne l'ajoute pas
             * comme clé puisqu'il n'est pas présent dans cette page.
             */
            if (
                $conflictingId !== null
                && $conflicting->getRule()?->getId()
                === $programmationRule->getId()
            ) {
                $conflictsBySlotId[$conflictingId][] = $candidate;
            }
        }

        return $this->render(
            'admin/programmationRuleSlot/index.html.twig',
            [
                'rule' => $programmationRule,
                'slots' => $slots,
                'conflictsBySlotId' => $conflictsBySlotId,
            ]
        );
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(
        int $ruleId,
        Request $request,
        ProgrammationRuleRepository $programmationRuleRepository,
        EntityManagerInterface $em,
        ProgrammationRuleConflictChecker $conflictChecker,
        ProgrammationRuleMutationInterface $mutationService
    ): Response {
        $programmationRule = $programmationRuleRepository->find($ruleId);

        if (!$programmationRule || $programmationRule->isDeleted()) {
            throw $this->createNotFoundException(
                'Règle de programmation introuvable.'
            );
        }

        $programmationRuleSlot = new ProgrammationRuleSlot();
        $programmationRule->addSlot($programmationRuleSlot);

        $form = $this->createForm(
            ProgrammationRuleSlotType::class,
            $programmationRuleSlot
        );

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateSlotForm(
                $form,
                $programmationRuleSlot
            );

            if ($form->isValid()) {
                $hasConflict = $conflictChecker
                    ->hasRuleStructuralConflicts(
                        $programmationRule
                    );

                /*
             * Un conflit structurel désactive automatiquement la règle.
             *
             * IMPORTANT :
             *
             * cette désactivation automatique n'est PAS une invalidation
             * explicite de la règle.
             *
             * Les affectations existantes doivent donc être synchronisées
             * avec la nouvelle structure, et non supprimées intégralement.
             */
                if (
                    $hasConflict
                    && $programmationRule->isActive()
                ) {
                    $programmationRule->setIsActive(false);
                }

                /*
             * Le nouveau slot doit appartenir à l'UnitOfWork avant
             * l'orchestration.
             *
             * Aucun flush préalable n'est nécessaire :
             *
             * - le builder sait travailler avec un slot dont l'ID est null ;
             * - le MutationService détermine les semaines publiées impactées ;
             * - le synchronizer sait ensuite rattacher les Drafts au nouvel
             *   objet slot avant son attribution d'un ID SQL.
             */
                $em->persist($programmationRuleSlot);

                /*
             * L'orchestrateur prend désormais en charge l'ensemble de
             * l'opération structurelle :
             *
             * - analyse des semaines publiées impactées ;
             * - prévalidation de leur dévalidation ;
             * - dévalidation des semaines concernées ;
             * - synchronisation des Drafts et du Parc ;
             * - flush de l'opération.
             */
                $mutationService->synchronizeAfterStructuralChange(
                    $programmationRule
                );

                if ($hasConflict) {
                    $this->addFlash(
                        'warning',
                        'Le créneau a bien été créé, mais la règle a été désactivée car elle présente un conflit de programmation.'
                    );
                } else {
                    $this->addFlash(
                        'success',
                        'Le créneau a bien été créé.'
                    );
                }

                return $this->redirectToRoute(
                    'admin_programmationRuleSlot_index',
                    [
                        'ruleId' => $programmationRule->getId(),
                    ]
                );
            }
        }

        return $this->render(
            'admin/programmationRuleSlot/create.html.twig',
            [
                'form' => $form->createView(),
                'rule' => $programmationRule,
                'form_title' => 'Créer un créneau',
            ]
        );
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $ruleId,
        ProgrammationRuleSlot $programmationRuleSlot,
        Request $request,
        ProgrammationRuleRepository $programmationRuleRepository,
        ProgrammationRuleConflictChecker $conflictChecker,
        ProgrammationRuleMutationInterface $mutationService
    ): Response {
        $programmationRule = $programmationRuleRepository->find($ruleId);

        if (!$programmationRule || $programmationRule->isDeleted()) {
            throw $this->createNotFoundException(
                'Règle de programmation introuvable.'
            );
        }

        if ($programmationRuleSlot->isDeleted()) {
            $this->addFlash(
                'danger',
                'Ce créneau a été supprimé.'
            );

            return $this->redirectToRoute(
                'admin_programmationRuleSlot_index',
                [
                    'ruleId' => $ruleId,
                ]
            );
        }

        if (
            $programmationRuleSlot->getRule()?->getId()
            !== $programmationRule->getId()
        ) {
            throw $this->createNotFoundException(
                'Ce créneau n’appartient pas à cette règle.'
            );
        }

        $form = $this->createForm(
            ProgrammationRuleSlotType::class,
            $programmationRuleSlot
        );

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateSlotForm(
                $form,
                $programmationRuleSlot
            );

            if ($form->isValid()) {
                $hasConflict = $conflictChecker
                    ->hasRuleStructuralConflicts(
                        $programmationRule
                    );

                /*
             * Une désactivation automatique due à un conflit ne constitue
             * jamais une demande de suppression des groupes existants.
             *
             * La modification reste une synchronisation structurelle.
             */
                if (
                    $hasConflict
                    && $programmationRule->isActive()
                ) {
                    $programmationRule->setIsActive(false);
                }

                /*
             * Le formulaire a déjà appliqué la nouvelle structure au slot
             * géré par Doctrine.
             *
             * Le MutationService compare donc :
             *
             * - les Drafts encore porteurs de l'ancienne programmation ;
             * - la règle et ses slots déjà porteurs de la nouvelle structure.
             *
             * Il dévalide les semaines publiées nécessaires avant de laisser
             * le synchronizer modifier les Drafts.
             */
                $mutationService->synchronizeAfterStructuralChange(
                    $programmationRule
                );

                if ($hasConflict) {
                    $this->addFlash(
                        'warning',
                        'Le créneau a bien été modifié, mais la règle a été désactivée car elle présente un conflit de programmation.'
                    );
                } elseif (!$programmationRule->isActive()) {
                    $this->addFlash(
                        'success',
                        'Le créneau a bien été modifié. La règle ne présente plus de conflit et peut être réactivée.'
                    );
                } else {
                    $this->addFlash(
                        'success',
                        'Le créneau a bien été modifié.'
                    );
                }

                return $this->redirectToRoute(
                    'admin_programmationRuleSlot_index',
                    [
                        'ruleId' => $programmationRule->getId(),
                    ]
                );
            }
        }

        return $this->render(
            'admin/programmationRuleSlot/edit.html.twig',
            [
                'form' => $form->createView(),
                'rule' => $programmationRule,
                'slot' => $programmationRuleSlot,
                'form_title' => 'Modifier un créneau',
            ]
        );
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        int $ruleId,
        ProgrammationRuleSlot $programmationRuleSlot,
        Request $request,
        EntityManagerInterface $em,
        ProgrammationRuleMutationInterface $mutationService
    ): Response {
        $programmationRule = $em
            ->getRepository(ProgrammationRule::class)
            ->find($ruleId);

        if (!$programmationRule || $programmationRule->isDeleted()) {
            throw $this->createNotFoundException(
                'Règle de programmation introuvable.'
            );
        }

        if (
            $programmationRuleSlot->getRule()?->getId()
            !== $programmationRule->getId()
        ) {
            throw $this->createNotFoundException(
                'Ce créneau n’appartient pas à cette règle.'
            );
        }

        if (
            !$this->isCsrfTokenValid(
                'delete_slot_' . $programmationRuleSlot->getId(),
                (string) $request->request->get('_token')
            )
        ) {
            $this->addFlash(
                'danger',
                'Jeton CSRF invalide.'
            );

            return $this->redirectToRoute(
                'admin_programmationRuleSlot_index',
                [
                    'ruleId' => $programmationRule->getId(),
                ]
            );
        }

        if ($programmationRuleSlot->isDeleted()) {
            $this->addFlash(
                'warning',
                'Ce créneau est déjà supprimé.'
            );

            return $this->redirectToRoute(
                'admin_programmationRuleSlot_index',
                [
                    'ruleId' => $programmationRule->getId(),
                ]
            );
        }

        /*
     * Le soft delete doit être visible dans l'UnitOfWork AVANT
     * l'orchestration.
     *
     * Le builder ne considérera ainsi plus ce slot dans la nouvelle
     * structure de la règle.
     */
        $programmationRuleSlot->softDelete();

        /*
     * Le MutationService détermine d'abord les semaines publiées impactées.
     *
     * Si le slot supprimé correspond à :
     *
     * - une première diffusion : le groupe futur sera invalidé ;
     * - une rediffusion régulière : elle sera supprimée et renvoyée au Parc ;
     * - une programmation déjà historique : le groupe restera gelé.
     *
     * Toute semaine publiée devant être modifiée est dévalidée avant
     * la synchronisation.
     */
        $mutationService->synchronizeAfterStructuralChange(
            $programmationRule
        );

        $this->addFlash(
            'success',
            'Le créneau a bien été supprimé.'
        );

        return $this->redirectToRoute(
            'admin_programmationRuleSlot_index',
            [
                'ruleId' => $programmationRule->getId(),
            ]
        );
    }

    private function validateSlotForm(
        FormInterface $form,
        ProgrammationRuleSlot $programmationRuleSlot
    ): void {
        $recurrenceType = $programmationRuleSlot->getRecurrenceType();
        $monthlyOccurrence = $programmationRuleSlot->getMonthlyOccurrence();
        $monthInterval = $programmationRuleSlot->getMonthInterval();
        $weekOffset = $programmationRuleSlot->getWeekOffset();
        $broadcastRank = $programmationRuleSlot->getBroadcastRank();
        $durationMinutes = $programmationRuleSlot->getDurationMinutes();
        $weekParity = $programmationRuleSlot->getWeekParity();

        if (
            $recurrenceType
            === ProgrammationRuleSlot::RECURRENCE_WEEKLY
        ) {
            $programmationRuleSlot->setMonthlyOccurrence(null);
            $programmationRuleSlot->setMonthInterval(1);
        }

        if (
            $recurrenceType
            === ProgrammationRuleSlot::RECURRENCE_MONTHLY
        ) {
            $programmationRuleSlot->setWeekParity(null);

            if ($monthlyOccurrence === null) {
                $form
                    ->get('monthlyOccurrence')
                    ->addError(
                        new FormError(
                            'Ce champ est obligatoire pour une programmation mensuelle.'
                        )
                    );
            }

            if ($monthInterval < 1) {
                $form
                    ->get('monthInterval')
                    ->addError(
                        new FormError(
                            'L’intervalle mensuel doit être supérieur ou égal à 1.'
                        )
                    );
            }
        }

        if (!in_array($weekOffset, [0, 1, 2, 3, 4], true)) {
            $form
                ->get('weekOffset')
                ->addError(
                    new FormError(
                        'Le décalage en semaines radio sélectionné est invalide.'
                    )
                );
        }

        if (!in_array($broadcastRank, [1, 2, 3, 4], true)) {
            $form
                ->get('broadcastRank')
                ->addError(
                    new FormError(
                        'L’ordre de diffusion sélectionné est invalide.'
                    )
                );
        }

        if (
            !in_array(
                $weekParity,
                [
                    null,
                    ProgrammationRuleSlot::WEEK_PARITY_EVEN,
                    ProgrammationRuleSlot::WEEK_PARITY_ODD,
                ],
                true
            )
        ) {
            $form
                ->get('weekParity')
                ->addError(
                    new FormError(
                        'Le rythme hebdomadaire sélectionné est invalide.'
                    )
                );
        }

        if (
            $durationMinutes === null
            || $durationMinutes < 1
        ) {
            $form
                ->get('durationMinutes')
                ->addError(
                    new FormError(
                        'La durée doit être supérieure à 0.'
                    )
                );
        }
    }
}
