<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend_webform\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\jsonapi_frontend\Service\PathResolverInterface;
use Drupal\jsonapi_frontend_webform\Service\WebformPathResolver;
use Drupal\path_alias\AliasManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for the Webform resolver decorator.
 *
 * @group jsonapi_frontend_webform
 */
final class WebformPathResolverTest extends TestCase {

  private function notFound(): array {
    return [
      'resolved' => FALSE,
      'kind' => NULL,
      'canonical' => NULL,
      'entity' => NULL,
      'redirect' => NULL,
      'jsonapi_url' => NULL,
      'data_url' => NULL,
      'headless' => FALSE,
      'drupal_url' => NULL,
    ];
  }

  private function createConfigFactory(array $values): ConfigFactoryInterface {
    $config = new class($values) {
      public function __construct(private readonly array $values) {}

      public function get(string $key): mixed {
        return $this->values[$key] ?? NULL;
      }
    };

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('jsonapi_frontend.settings')->willReturn($config);

    return $factory;
  }

  public function testReturnsInnerResultWhenAlreadyResolved(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())
      ->method('resolve')
      ->willReturn([
        'resolved' => TRUE,
        'kind' => 'entity',
        'canonical' => '/about-us',
        'entity' => ['type' => 'node--page', 'id' => 'uuid', 'langcode' => 'en'],
        'redirect' => NULL,
        'jsonapi_url' => '/jsonapi/node/page/uuid',
        'data_url' => NULL,
        'headless' => TRUE,
        'drupal_url' => NULL,
      ]);

    $resolver = new WebformPathResolver(
      $inner,
      $this->createMock(AliasManagerInterface::class),
      $this->createMock(PathValidatorInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/about-us', 'en');
    $this->assertTrue($result['resolved']);
    $this->assertSame('entity', $result['kind']);
  }

  public function testNoWebformModuleReturnsInnerResult(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(FALSE);

    $resolver = new WebformPathResolver(
      $inner,
      $this->createMock(AliasManagerInterface::class),
      $this->createMock(PathValidatorInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact', 'en');
    $this->assertFalse($result['resolved']);
  }

  public function testReturnsInnerResultWhenPathTooLong(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects(self::never())->method('getPathByAlias');

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $this->createMock(PathValidatorInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve(str_repeat('a', 2050), 'en');
    $this->assertFalse($result['resolved']);
  }

  public function testResolvesWebformRouteUsingSiteDefaultLanguageFallbackAndRequestBaseUrl(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->expects(self::once())->method('getDefaultLanguage')->willReturn($language);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects(self::once())
      ->method('getPathByAlias')
      ->with('/contact', 'en')
      ->willReturn('/form/contact');
    $alias_manager->expects(self::once())
      ->method('getAliasByPath')
      ->with('/form/contact', 'en')
      ->willReturn('');

    $url = $this->createMock(Url::class);
    $url->method('access')->willReturn(TRUE);

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->expects(self::once())->method('getUrlIfValid')->with('/form/contact')->willReturn($url);

    $config_factory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'site_default',
      'drupal_base_url' => '',
    ]);

    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn(Request::create('https://cms.example.com'));

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $language_manager,
      $module_handler,
      $config_factory,
      $request_stack,
    );

    $result = $resolver->resolve('contact/?utm=1#frag');

    $this->assertTrue($result['resolved']);
    $this->assertSame('route', $result['kind']);
    $this->assertSame('/contact', $result['canonical']);
    $this->assertSame('https://cms.example.com/contact', $result['drupal_url']);
  }

  public function testResolvesWebformRouteUsingCurrentLanguageFallback(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('fr');

    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->expects(self::once())
      ->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($language);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects(self::once())
      ->method('getPathByAlias')
      ->with('/contact', 'fr')
      ->willReturn('/form/contact');
    $alias_manager->expects(self::once())
      ->method('getAliasByPath')
      ->with('/form/contact', 'fr')
      ->willReturn('/contact');

    $url = $this->createMock(Url::class);
    $url->method('access')->willReturn(TRUE);

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->expects(self::once())->method('getUrlIfValid')->with('/form/contact')->willReturn($url);

    $config_factory = $this->createConfigFactory([
      'resolver.langcode_fallback' => 'current',
      'drupal_base_url' => 'https://cms.example.com',
    ]);

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $language_manager,
      $module_handler,
      $config_factory,
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact');

    $this->assertTrue($result['resolved']);
    $this->assertSame('/contact', $result['canonical']);
  }

  public function testReturnsInnerResultWhenInternalPathIsNotAWebformRoute(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects(self::once())->method('getPathByAlias')->with('/contact', 'en')->willReturn('/node/1');
    $alias_manager->expects(self::never())->method('getAliasByPath');

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->expects(self::never())->method('getUrlIfValid');

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $this->createConfigFactory([]),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact', 'en');
    $this->assertFalse($result['resolved']);
  }

  public function testReturnsInnerResultWhenAliasResolutionFails(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects(self::once())->method('getPathByAlias')->willReturn(NULL);

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $this->createMock(PathValidatorInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $this->createConfigFactory([]),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact', 'en');
    $this->assertFalse($result['resolved']);
  }

  public function testReturnsInnerResultWhenUrlIsInvalid(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getPathByAlias')->with('/contact', 'en')->willReturn('/form/contact');

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->method('getUrlIfValid')->with('/form/contact')->willReturn(NULL);

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $this->createConfigFactory([]),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact', 'en');
    $this->assertFalse($result['resolved']);
  }

  public function testReturnsInnerResultWhenAccessDenied(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getPathByAlias')->with('/contact', 'en')->willReturn('/form/contact');

    $url = $this->createMock(Url::class);
    $url->method('access')->willReturn(FALSE);

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->method('getUrlIfValid')->with('/form/contact')->willReturn($url);

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $this->createConfigFactory([]),
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact', 'en');
    $this->assertFalse($result['resolved']);
  }

  public function testDrupalUrlFallsBackToPathWhenNoConfiguredBaseUrlAndNoRequest(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getPathByAlias')->with('/contact', 'en')->willReturn('/form/contact');
    $alias_manager->method('getAliasByPath')->with('/form/contact', 'en')->willReturn('/contact');

    $url = $this->createMock(Url::class);
    $url->method('access')->willReturn(TRUE);

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->method('getUrlIfValid')->with('/form/contact')->willReturn($url);

    $config_factory = $this->createConfigFactory([
      'drupal_base_url' => '',
    ]);

    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn(NULL);

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $config_factory,
      $request_stack,
    );

    $result = $resolver->resolve('/contact', 'en');
    $this->assertTrue($result['resolved']);
    $this->assertSame('/contact', $result['drupal_url']);
  }

  public function testResolvesWebformRouteFromAlias(): void {
    $inner = $this->createMock(PathResolverInterface::class);
    $inner->expects(self::once())->method('resolve')->willReturn($this->notFound());

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getPathByAlias')->with('/contact', 'en')->willReturn('/form/contact');
    $alias_manager->method('getAliasByPath')->with('/form/contact', 'en')->willReturn('/contact');

    $url = $this->createMock(Url::class);
    $url->method('access')->willReturn(TRUE);

    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->method('getUrlIfValid')->with('/form/contact')->willReturn($url);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('webform')->willReturn(TRUE);

    $config = new class {
      public function get(string $key): mixed {
        return match ($key) {
          'drupal_base_url' => 'https://cms.example.com',
          default => NULL,
        };
      }
    };

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('jsonapi_frontend.settings')->willReturn($config);

    $resolver = new WebformPathResolver(
      $inner,
      $alias_manager,
      $path_validator,
      $this->createMock(LanguageManagerInterface::class),
      $module_handler,
      $config_factory,
      $this->createMock(RequestStack::class),
    );

    $result = $resolver->resolve('/contact', 'en');

    $this->assertTrue($result['resolved']);
    $this->assertSame('route', $result['kind']);
    $this->assertSame('/contact', $result['canonical']);
    $this->assertFalse($result['headless']);
    $this->assertSame('https://cms.example.com/contact', $result['drupal_url']);
  }

}
