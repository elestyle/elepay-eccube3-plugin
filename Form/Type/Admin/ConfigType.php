<?php

namespace Plugin\Elepay\Form\Type\Admin;

use Eccube\Application;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Plugin\Elepay\Entity\ElepayConfig;

class ConfigType extends AbstractType
{
    /**
     * @var Application
     */
    protected $app;

    protected $eccubeConfig;

    /**
     * ConfigType constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->eccubeConfig = $app['config'];
    }

    /**
     * Build config type form
     *
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            // ChoiceType Document
            // https://symfony.com/doc/current/reference/forms/types/choice.html
            ->add('public_key', 'text', [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => '※ パブリックキーが入力されていません。']),
                    new Assert\Length(['max' => $this->eccubeConfig['smtext_len']]),
                    new Assert\Regex([
                        'pattern' => '/^[[:graph:]]+$/i',
                        'message' => 'form_error.graph_only',
                    ]),
                ],
            ])

            ->add('secret_key', 'text', [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => '※ シークレットキーが入力されていません。']),
                    new Assert\Length(['max' => $this->eccubeConfig['smtext_len']]),
                    new Assert\Regex([
                        'pattern' => '/^[[:graph:]]+$/',
                        'message' => 'form_error.graph_only',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => ElepayConfig::class,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return 'elepay_config';
    }
}
