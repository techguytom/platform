<?php

namespace Oro\Bundle\LocaleBundle\Tests\EventListener;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

use Oro\Bundle\LocaleBundle\EventListener\LocaleListener;

class LocaleListenerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var LocaleListener
     */
    protected $listener;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $localeSettings;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $transListener;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $router;

    /**
     * @var string
     */
    protected $defaultLocale;

    protected function setUp()
    {
        $this->localeSettings = $this->getMockBuilder('Oro\Bundle\LocaleBundle\Model\LocaleSettings')
            ->disableOriginalConstructor()
            ->getMock();
        $this->transListener = $this->getMock('Gedmo\Translatable\TranslatableListener');
        $this->router = $this->getMock('Symfony\Component\Routing\RequestContextAwareInterface');

        $this->defaultLocale = \Locale::getDefault();
    }

    protected function tearDown()
    {
        \Locale::setDefault($this->defaultLocale);
    }

    /**
     * @param mixed $installed
     * @param bool $isSetLocale
     * @dataProvider onKernelRequestDataProvider
     */
    public function testOnKernelRequest($installed, $isSetLocale)
    {
        $customLanguage = 'ru';
        $customLocale = 'fr';

        $request = new Request();
        $context = new RequestContext();
        $request->setDefaultLocale($this->defaultLocale);

        if ($isSetLocale) {
            $this->localeSettings->expects($this->exactly(3))->method('getLanguage')
                ->will($this->returnValue($customLanguage));
            $this->localeSettings->expects($this->once())->method('getLocale')
                ->will($this->returnValue($customLocale));
            $this->router->expects($this->once())->method('getContext')->willReturn($context);
        } else {
            $this->localeSettings->expects($this->never())->method('getLanguage');
            $this->localeSettings->expects($this->never())->method('getLocale');
        }

        $this->listener = new LocaleListener(
            $this->localeSettings,
            $this->transListener,
            $installed,
            $this->router
        );
        $this->listener->onKernelRequest($this->createGetResponseEvent($request));

        if ($isSetLocale) {
            $this->assertEquals($customLanguage, $request->getLocale());
            $this->assertEquals($customLanguage, $context->getParameter('_locale'));
            $this->assertEquals($customLocale, \Locale::getDefault());
        } else {
            $this->assertEquals($this->defaultLocale, $request->getLocale());
            $this->assertEquals($this->defaultLocale, \Locale::getDefault());
        }
    }

    public function onKernelRequestDataProvider()
    {
        return array(
            'application not installed with null' => array(
                'installed' => null,
                'isSetLocale' => false,
            ),
            'application not installed with false' => array(
                'installed' => false,
                'isSetLocale' => false,
            ),
            'application installed with flag' => array(
                'installed' => true,
                'isSetLocale' => true,
            ),
            'application installed with date' => array(
                'installed' => '2012-12-12T12:12:12+02:00',
                'isSetLocale' => true,
            ),
        );
    }

    public function testOnConsoleCommand()
    {
        $this->listener = new LocaleListener(
            $this->localeSettings,
            $this->transListener,
            true
        );

        $event = $this
            ->getMockBuilder('Symfony\Component\Console\Event\ConsoleCommandEvent')
            ->disableOriginalConstructor()
            ->getMock();

        $input = $this
            ->getMockBuilder('Symfony\Component\Console\Input\InputInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getInput')
            ->will($this->returnValue($input));

        $input
            ->expects($this->once())
            ->method('hasParameterOption')
            ->will($this->returnValue(false));

        $this->localeSettings
            ->expects($this->once())
            ->method('getLocale');

        $this->localeSettings
            ->expects($this->once())
            ->method('getLanguage');

        $this->transListener
            ->expects($this->once())
            ->method('setTranslatableLocale');

        $this->listener->onConsoleCommand($event);
    }

    /**
     * @param Request $request
     * @return GetResponseEvent
     */
    protected function createGetResponseEvent(Request $request)
    {
        $event = $this->getMockBuilder('Symfony\Component\HttpKernel\Event\GetResponseEvent')
            ->disableOriginalConstructor()
            ->setMethods(array('getRequest'))
            ->getMock();

        $event->expects($this->once())
            ->method('getRequest')
            ->will($this->returnValue($request));

        return $event;
    }
}
