<?php

/**
 * @file
 * Contains Drupal\search404\Controller\Search404Controller.
 */

namespace Drupal\search404\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\search\Entity\SearchPage;
use Drupal\Component\Utility\Html;

/**
 * Route controller for search.
 */
class Search404Controller extends ControllerBase {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Inject the logger channel factory interface.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('search404');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Set title for the page not found(404) page.
   */
  public function getTitle() {
    $search404_page_title = \Drupal::config('search404.settings')->get('search404_page_title');
    $title = !empty($search404_page_title) ? $search404_page_title : 'Page not found ';
    return $title;
  }

  /**
   * {@inheritdoc}
   */
  public function search404_page(Request $request) {
    if (\Drupal::moduleHandler()->moduleExists('search') && (\Drupal::currentUser()->hasPermission('search content') || \Drupal::currentUser()->hasPermission('search by page'))) {

      // Get and use the default search engine for the site.
      $search_page_repository = \Drupal::service('search.search_page_repository');
      $default_search_page = $search_page_repository->getDefaultSearchPage();

      $entity = SearchPage::load($default_search_page);
      $plugin = $entity->getPlugin();
      $build = array();
      $results = array();

      // Build the form first, because it may redirect during the submit,
      // and we don't want to build the results based on last time's request.
      $keys = search404_get_keys();
      $plugin->setSearch($keys, $request->query->all(), $request->attributes->all());


      if ($keys && !\Drupal::config('search404.settings')->get('search404_skip_auto_search')) {
        //if custom search enabled.
        if (\Drupal::config('search404.settings')->get('search404_do_custom_search')) {
          if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
            drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => Html::escape($keys))), 'error');
          }
          $custom_search_path = \Drupal::config('search404.settings')->get('search404_custom_search_path');
          $custom_search_path = str_replace('@keys', $keys, $custom_search_path);
          search404_goto($custom_search_path);
        }
        else {
          // Build search results, if keywords or other search parameters are in the
          // GET parameters. Note that we need to try the search if 'keys' is in
          // there at all, vs. being empty, due to advanced search.
          if ($plugin->isSearchExecutable()) {
            // Log the search.
            if ($this->config('search.settings')->get('logging')) {
              $this->logger->notice('Searched %type for %keys.', array('%keys' => $keys, '%type' => $entity->label()));
            }
            // Collect the search results.
            $results = $plugin->buildResults();
          }

          if (isset($results)) {
            // Jump to first result if there are results and
            // if there is only one result and if jump to first is selected or
            // if there are more than one results and force jump to first is selected.
            if (is_array($results) &&
                (
                (count($results) == 1 && \Drupal::config('search404.settings')->get('search404_jump')) || (count($results) >= 1 && \Drupal::config('search404.settings')->get('search404_first'))
                )
            ) {
              if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
                drupal_set_message(t('The page you requested does not exist. A search for %keys resulted in this page.', array('%keys' => Html::escape($keys))), 'status');
              }
              if (isset($results[0]['#result']['link'])) {
                $result_path = $results[0]['#result']['link'];
              }
              search404_goto($result_path);
            }
            else {
              if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
                drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => Html::escape($keys))), 'error');
              }
            }
          }
        }
      }

      // Construct the search form.
      $build['search_form'] = $this->entityFormBuilder()->getForm($entity, 'search');

      //Set the custom page text on the top of the results.
      $search404_page_text = \Drupal::config('search404.settings')->get('search404_page_text');
      if (!empty($search404_page_text)) {
        $build['content']['#markup'] = '<div id="search404-page-text">' . $search404_page_text . '</div>';
      }

      //Text for,if search results is empty.
      $no_results = t('<ul>
     <li>Check if your spelling is correct.</li>
     <li>Remove quotes around phrases to search for each word individually. <em>bike shed</em> will often show more results than <em>&quot;bike shed&quot;</em>.</li>
     <li>Consider loosening your query with <em>OR</em>. <em>bike OR shed</em> will often show more results than <em>bike shed</em>.</li>
     </ul>');
      $build['search_results'] = array(
        '#theme' => array('item_list__search_results__' . $plugin->getPluginId(), 'item_list__search_results'),
        '#items' => $results,
        '#empty' => array(
          '#markup' => '<h3>' . $this->t('Your search yielded no results.') . '</h3>' . $no_results,
        ),
        '#list_type' => 'ol',
        '#attributes' => array(
          'class' => array(
            'search-results',
            $plugin->getPluginId() . '-results',
          ),
        ),
        '#cache' => array(
          'tags' => $entity->getCacheTags(),
        ),
      );

      $build['pager'] = array(
        '#theme' => 'pager',
      );
      $build['#attached']['library'][] = 'search/drupal.search.results';
      return $build;
    }
  }

}
