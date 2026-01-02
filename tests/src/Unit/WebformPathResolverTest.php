<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend_webform\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\jsonapi_frontend\Service\PathResolverInterface;
use Drupal\jsonapi_frontend_webform\Service\WebformPathResolver;
use Drupal\path_alias\AliasManagerInterface;
use PHPUnit\Framework\TestCase;
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

