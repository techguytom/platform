<?php

namespace Oro\Bundle\EntityExtendBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;

use Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\OptionSet;

/**
 * @deprecated since 1.4. Will be removed in 2.0
 */
class OptionSetCollectionType extends AbstractType
{
    /**
     * @var ConfigManager
     */
    protected $configManager;

    /**
     * @param ConfigManager $configManager
     */
    public function __construct(ConfigManager $configManager)
    {
        $this->configManager  = $configManager;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'postSubmitData']);
    }

    /**
     * @param FormEvent $event
     */
    public function postSubmitData(FormEvent $event)
    {
        $form        = $event->getForm();
        $data        = $event->getData();
        /** @var FieldConfigModel $configModel */
        $configModel = $form->getRoot()->getConfig()->getOptions()['config_model'];

        if (count($data)) {
            $em           = $this->configManager->getEntityManager();
            $optionValues = $oldOptions = $configModel->getOptions()->getValues();
            $newOptions   = [];
            array_walk_recursive(
                $oldOptions,
                function (&$oldOption) {
                    $oldOption = $oldOption->getId();
                }
            );

            foreach ($data as $option) {
                if (is_array($option)) {
                    $optionSet = new OptionSet();
                    $optionSet->setField($configModel);
                    $optionSet->setData(
                        $option['id'],
                        $option['priority'],
                        $option['label'],
                        (bool)$option['default']
                    );
                } elseif (!$option->getId()) {
                    $optionSet = $option;
                    $optionSet->setField($configModel);
                } else {
                    $optionSet = $option;
                }

                if ($optionSet->getLabel() != null) {
                    $newOptions[] = $optionSet->getId();
                }
                if (!in_array($optionSet, $optionValues) && $optionSet->getLabel() != null) {
                    $em->persist($optionSet);
                }
            }

            $delOptions = array_diff($oldOptions, $newOptions);
            foreach ($delOptions as $key => $delOption) {
                $em->remove($configModel->getOptions()->getValues()[$key]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(
            array(
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__name__',
                'extra_fields_message' => 'This form should not contain extra fields: "{{ extra_fields }}"',
                'show_form_when_empty' => true
            )
        );
        $resolver->setRequired(array('type'));
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars = array_replace(
            $view->vars,
            array(
                'show_form_when_empty' => $options['show_form_when_empty'],
                'allow_add_after'      => false
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return 'collection';
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'oro_entity_option_set_collection';
    }
}
