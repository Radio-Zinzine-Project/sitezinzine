<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Form\ProgrammationRuleSlotType;
use App\Form\ProgrammationRuleType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** HTTP rendering contracts: real Symfony forms and Twig, no persistence service invoked. */
final class ProgrammationFormErrorsTest extends WebTestCase
{
    private const GLOBAL_ERROR = 'La modification de programmation a été refusée.';
    private const FIELD_ERROR = 'Erreur du champ témoin.';
    private const CSRF_ERROR = 'Le jeton CSRF est invalide.';

    public static function forms(): iterable
    {
        yield 'créneau' => ['slot'];
        yield 'règle' => ['rule'];
    }

    /** @dataProvider forms */
    public function testGlobalErrorIsVisible(string $kind): void
    {
        $crawler = $this->submit($kind, 'global');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::GLOBAL_ERROR, $crawler->filter('form')->text());
    }

    /** @dataProvider forms */
    public function testSymfonyCsrfErrorIsVisible(string $kind): void
    {
        $crawler = $this->submit($kind, 'csrf');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::CSRF_ERROR, $crawler->filter('form')->text());
        self::assertSame(1, substr_count($crawler->filter('form')->text(), self::CSRF_ERROR));
    }

    /** @dataProvider forms */
    public function testRejectedSubmissionKeepsSubmittedValues(string $kind): void
    {
        $crawler = $this->submit($kind, 'global');
        self::assertResponseStatusCodeSame(422);
        if ($kind === 'slot') {
            self::assertSame('3', $crawler->filter('[name="audit[monthlyOccurrence]"] option:selected')->attr('value'));
            self::assertSame('7', $crawler->filter('[name="audit[dayOfWeek]"] option:selected')->attr('value'));
            self::assertSame('22', $crawler->filter('[name="audit[startTime][hour]"] option:selected')->attr('value'));
            self::assertSame('90', $crawler->filter('[name="audit[durationMinutes]"]')->attr('value'));
        } else {
            self::assertSame('2026-11-15', $crawler->filter('[name="audit[validFrom]"]')->attr('value'));
            self::assertSame('2027-02-21', $crawler->filter('[name="audit[validUntil]"]')->attr('value'));
            self::assertCount(1, $crawler->filter('[name="audit[hasAdditionalRebroadcast]"]:checked'));
        }
    }

    /** @dataProvider forms */
    public function testGlobalAndFieldErrorsAreEachRenderedOnce(string $kind): void
    {
        $crawler = $this->submit($kind, 'both');
        $text = $crawler->filter('form')->text();
        self::assertSame(1, substr_count($text, self::FIELD_ERROR));
        self::assertSame(1, substr_count($text, self::GLOBAL_ERROR));
    }

    private function submit(string $kind, string $failure): \Symfony\Component\DomCrawler\Crawler
    {
        $client = self::createClient();
        $container = self::getContainer();
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('submit', 'valid-token'));
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === 'valid-token');

        // An ephemeral HTTP endpoint exercises the actual partial without accessing
        // admin authentication, repositories, mutation services or database fixtures.
        $container->get('event_dispatcher')->addListener(KernelEvents::REQUEST,
            function (RequestEvent $event) use ($container, $csrf, $kind, $failure): void {
                if ($event->getRequest()->getPathInfo() !== '/_test/programmation-form-errors') {
                    return;
                }
                $rule = new ProgrammationRule();
                (new \ReflectionProperty(ProgrammationRule::class, 'id'))->setValue($rule, 42);
                $slot = (new ProgrammationRuleSlot())->setRule($rule)->setRecurrenceType('monthly')
                    ->setMonthlyOccurrence(2)->setDayOfWeek(7)->setStartTime(new \DateTimeImmutable('22:00'))
                    ->setDurationMinutes(60);
                $builder = $container->get('form.factory')->createNamedBuilder('audit',
                    $kind === 'slot' ? ProgrammationRuleSlotType::class : ProgrammationRuleType::class,
                    $kind === 'slot' ? $slot : $rule,
                    ['csrf_protection' => true, 'csrf_token_manager' => $csrf, 'csrf_message' => self::CSRF_ERROR]);
                if ($kind === 'rule') {
                    // Category is disabled during editing. Replace only its database
                    // choice loader; error handling and submitted fields remain real.
                    $builder->add('category', ChoiceType::class, ['mapped' => false, 'disabled' => true, 'choices' => []]);
                }
                $form = $builder->getForm();
                $form->handleRequest($event->getRequest());
                self::assertTrue($form->isSubmitted());
                if ($failure !== 'csrf') {
                    self::assertTrue($form->isValid(), 'The initial submission must have no unrelated validation errors.');
                    $form->addError(new FormError(self::GLOBAL_ERROR));
                } else {
                    $errors = iterator_to_array($form->getErrors());
                    self::assertCount(1, $errors);
                    self::assertInstanceOf(CsrfToken::class, $errors[0]->getCause());
                }
                if ($failure === 'both') {
                    $form->get($kind === 'slot' ? 'durationMinutes' : 'validFrom')->addError(new FormError(self::FIELD_ERROR));
                }
                self::assertFalse($form->isValid());
                $html = $container->get('twig')->render('admin/' . ($kind === 'slot' ? 'programmationRuleSlot' : 'programmationRule') . '/_form.html.twig',
                    ['form' => $form->createView(), 'rule' => $rule, 'form_title' => 'Modifier la programmation']);
                $event->setResponse(new Response($html, 422));
            }, 256);

        $values = $kind === 'slot'
            ? ['recurrenceType' => 'monthly', 'monthlyOccurrence' => '3', 'monthInterval' => '1',
                'dayOfWeek' => '7', 'startTime' => ['hour' => '22', 'minute' => '0'],
                'durationMinutes' => '90', 'broadcastRank' => '1', 'weekOffset' => '0', 'isActive' => '1']
            : ['validFrom' => '2026-11-15', 'validUntil' => '2027-02-21', 'hasAdditionalRebroadcast' => '1'];
        $values['_token'] = $failure === 'csrf' ? 'invalid-token' : 'valid-token';
        return $client->request('POST', '/_test/programmation-form-errors', ['audit' => $values]);
    }
}
