<?php

/**
 * @file
 * Contains Drupal\search404\Controller\DefaultController.
 */

namespace Drupal\search404\Controller;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\search\SearchPageInterface;
use Drupal\search\SearchPageRepositoryInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Entity\EntityForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Routing\RouteMatchInterface;
/**
 * Route controller for search.
 */
class DefaultController extends ControllerBase {
  
  /**
   * {@inheritdoc}
   */
  public function search404_page() {
    $output = '';
    if (\Drupal::moduleHandler()->moduleExists('search') && (\Drupal::currentUser()->hasPermission('search content') || \Drupal::currentUser()->hasPermission('search by page'))) {
      $results = array();
      // set and use the default search engine for the site is 'node'.
      $type_search = 'node';
      $keys = search404_get_keys();
      // Get throttle status
      $throttle = \Drupal::moduleHandler()->invoke('throttle', 'status');
      // If search keys are present and site is not throttled and automatic searching is not disabled.
      if ($keys && !$throttle && !\Drupal::config('search404.settings')->get('search404_skip_auto_search')) {
        if (\Drupal::moduleHandler()->moduleExists('search_by_page') && \Drupal::config('search404.settings')->get('search404_do_search_by_page')) {
          if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
            drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => $keys)), 'error');
          }
          search404_goto('search_pages/' . $keys);
        }
        elseif (\Drupal::moduleHandler()->moduleExists('google_cse') && \Drupal::currentUser()->hasPermission('search Google CSE') && \Drupal::config('search404.settings')->get('search404_do_google_cse')) {
          if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
            drupal_set_message(t('The page you requested does not exist. For your convenience, a google search was performed using the query %keys.', array('%keys' => $keys)), 'error');
          }
          search404_goto('search/google/' . $keys);
        }
        elseif (\Drupal::config('search404.settings')->get('search404_do_custom_search')) {
          if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
            drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => $keys)), 'error');
          }
          $custom_search_path = \Drupal::config('search404.settings')->get('search404_custom_search_path');
          $custom_search_path = str_replace('@keys', $keys, $custom_search_path);
          search404_goto($custom_search_path);
        }
        else {
          // Called for core search assign as null.
          //we have to replace with search_data($keys, $type_search)
          $results = array();
          // Apache Solr puts the results in $results['search_results'].
          if (isset($results['search_results'])) {
            $results = $results['search_results'];
          }
          // Some modules like ds_search (#1253426) returns its own results format
          // and may not have $results['#results']
          if (isset($results['#results'])) {
            // Jump to first result if there are results and
            // if there is only one result and if jump to first is selected or
            // if there are more than one results and force jump to first is selected.
           if (is_array($results['#results']) &&
               (
                 (count($results['#results']) == 1 && \Drupal::config('search404.settings')->get('search404_jump'))
                   || (count($results['#results']) >= 1 && \Drupal::config('search404.settings')->get('search404_first'))
                 )
               ) {
                   if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
                     drupal_set_message(t('The page you requested does not exist. A search for %keys resulted in this page.', array('%keys' => $keys)), 'status');
                   }
                   if (isset($results['#results'][0]['node']->path)) {
                     $result_path = $results['#results'][0]['node']->path;
                   }
                   else {
                     $result_path = 'node/' . $results['#results'][0]['node']->nid;
                   }
                   search404_goto($result_path);
                }
                else {
                  if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
                    drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => $keys)), 'error');
                  }
                  if (isset($results['#results']) && count($results['#results']) >= 1) {
                    $css = array(
                      '#attached' => array(
                        'css' => array(
                          drupal_get_path('module', 'search') . 'css/search.admin.css' => array(),
                          drupal_get_path('module', 'search') . 'css/search.theme.css' => array(),
                        ),
                      ),
                    );
                  }
                  else {
                    $results['#markup'] = search_help('search#noresults', 'help.page.search');
                  }
              }
          }
          else {
            // Normal $results['#results'] doesn't exist, we will not redirect
            // and just hope the strange search module knows how to render its output.
            if (!\Drupal::config('search404.settings')->get('search404_disable_error_message')) {
              drupal_set_message(t('The page you requested does not exist. For your convenience, a search was performed using the query %keys.', array('%keys' => $keys)), 'error');
            }
          }
        }
      }
      // Construct the search form.
       $form = \Drupal::formBuilder()->getForm('Drupal\search\Form\SearchPageForm', NULL, $keys, $type_search);
       $output = drupal_render($form) . drupal_render($results);
      // Add custom text before the search form and results if custom text has been set
      $search404_page_text = \Drupal::config('search404.settings')->get('search404_page_text');
      if (!empty($search404_page_text)) {
        $output = '<div id="search404-page-text">' . $search404_page_text . '</div>' . $output;
      }
    }
    // If the user does not have search permissions $output would be empty.
    if ($output == '') {
      $output = t('The page you requested does not exist.');
    }
    return $output;
  }
}
