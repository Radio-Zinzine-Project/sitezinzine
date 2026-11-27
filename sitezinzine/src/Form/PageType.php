<?php
namespace App\Form;

use App\Entity\Page;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // slug (par défaut modifiable)
        $builder
            ->add('slug', TextType::class, [
                'label' => 'Identifiant de la page (slug)',
                'help'  => 'Ex : soutien, a-propos, infos-pratiques',
            ])
            ->add('title', TextType::class, [
                'label' => 'Titre',
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => [
                    'class' => 'tinymce',
                    'rows' => 15,
                ],
            ])
            ->add('mainImageFile', FileType::class, [
                'label'    => 'Image de tête (optionnelle)',
                'required' => false,
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $page = $event->getData();
            $form = $event->getForm();

            if (!$page) {
                return;
            }

            // ✅ EDITION : slug visible mais non modifiable
            if (null !== $page->getId()) {
                $form->add('slug', TextType::class, [
                    'label' => 'Identifiant de la page (slug)',
                    'help'  => 'Non modifiable après création',
                    'disabled' => true, // affiché mais non éditable / non soumis
                ]);
            }

            // ✅ Checkbox suppression : uniquement si une image existe
            if ($page->getMainImageName()) {
                $form->add('deleteMainImage', CheckboxType::class, [
                    'required' => false,
                    'mapped' => false, // on gère la suppression dans le controller
                    'label' => 'Supprimer l’image de tête',
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Page::class,
        ]);
    }
}
