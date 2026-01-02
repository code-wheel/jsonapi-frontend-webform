<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend_webform\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\jsonapi_frontend\Service\PathResolverInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decorates jsonapi_frontend resolver with Drupal Webform route support.
 *
 * Webforms are config entities and do not map to JSON:API resource URLs.
 * This decorator ensures Webform routes resolve as non-headless so frontends
 * can redirect/proxy to Drupal for rendering + submissions.
 */
final class WebformPathResolver implements PathResolverInterface {

  public function __construct(
    private readonly PathResolverInterface $inner,
    private readonly AliasManagerInterface $aliasManager,
    private readonly PathValidatorInterface $pathValidator,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {}

  public function resolve(string $path, ?string $langcode = NULL): array {
    $result = $this->inner->resolve($path, $langcode);
    if (($result['resolved'] ?? FALSE) === TRUE) {
      return $result;
    }

    if (!$this->moduleHandler->moduleExists('webform')) {
      return $result;
    }

    [$path, ] = $this->splitPathAndQuery($path);
    $path = $this->normalizePath($path);
    if ($path === '') {
      return $result;
    }

    $effective_langcode = $this->getEffectiveLangcode($langcode);

    $internal = $this->aliasManager->getPathByAlias($path, $effective_langcode);
    if (!is_string($internal) || $internal === '') {
      return $result;
    }

    // Webforms are typically served at /form/{webform_id}. Aliases are common,
    // so we check the internal target after alias resolution.
    if (!str_starts_with($internal, '/form/')) {
      return $result;
    }

    $url = $this->pathValidator->getUrlIfValid($internal);
    if (!$url) {
      return $result;
    }

    // Enforce access checks and treat restricted forms as not found.
    if (!$url->access()) {
      return $result;
    }

    $current_alias = $this->aliasManager->getAliasByPath($internal, $effective_langcode);
    $canonical = ($current_alias && $current_alias !== '') ? $current_alias : $path;

    return [
      'resolved' => TRUE,
      // NOTE: jsonapi_frontend core currently documents entity/view/redirect.
      // Add-on route kinds should still follow the same shape so frontends can
      // proxy/redirect when headless=false and drupal_url is present.
      'kind' => 'route',
      'canonical' => $canonical,
      'entity' => NULL,
      'redirect' => NULL,
      'jsonapi_url' => NULL,
      'data_url' => NULL,
      'headless' => FALSE,
      'drupal_url' => $this->getDrupalUrl($canonical),
    ];
  }

  private function getEffectiveLangcode(?string $langcode): string {
    if (is_string($langcode) && $langcode !== '') {
      return $langcode;
    }

    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $fallback = (string) ($config->get('resolver.langcode_fallback') ?? 'site_default');

    if ($fallback === 'current') {
      return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    }

    return $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * Get the Drupal frontend URL for a path.
   */
  private function getDrupalUrl(string $path): string {
    $config = $this->configFactory->get('jsonapi_frontend.settings');
    $base_url = $config->get('drupal_base_url');

    // If no base URL configured, use the current site URL.
    if (empty($base_url)) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request) {
        $base_url = $request->getSchemeAndHttpHost();
      }
      else {
        $base_url = '';
      }
    }

    $base_url = rtrim((string) $base_url, '/');

    return $base_url . $path;
  }

  private function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '') {
      return '';
    }
    if (strlen($path) > 2048) {
      return '';
    }
    $path = preg_replace('/[?#].*$/', '', $path) ?? $path;
    if ($path === '') {
      return '';
    }
    if ($path[0] !== '/') {
      $path = '/' . $path;
    }
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if ($path !== '/' && str_ends_with($path, '/')) {
      $path = rtrim($path, '/');
    }
    return $path;
  }

  /**
   * @return array{0: string, 1: array}
   */
  private function splitPathAndQuery(string $path): array {
    $path = trim($path);
    if ($path === '') {
      return ['', []];
    }

    if (str_contains($path, '#')) {
      $path = strstr($path, '#', TRUE) ?: '';
    }

    $qpos = strpos($path, '?');
    if ($qpos === FALSE) {
      return [$path, []];
    }

    $raw_path = substr($path, 0, $qpos);

    return [$raw_path, []];
  }

}

