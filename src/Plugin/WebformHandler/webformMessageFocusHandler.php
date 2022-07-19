<?php

namespace Drupal\webform_messagefocus\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "webform_messagefocus_handler",
 *   label = @Translation("MessageFocus Handler"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("Send submissions to MessageFocus"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class webformMessageFocusHandler extends WebformHandlerBase
{

  /**
   * Custom element property key used to store Webform->Messagefocus field name mappings
   */
  const MAPPING_KEY = 'messagefocus_field_id';

  /**
   * Message text for validation responses.
   */
  const MESSAGE_SUBMISSION_BAD_RESPONSE_CODE = 'There is a problem with the information you have supplied - please amend and try again';
  const MESSAGE_SUBMISSION_CLIENT_EXCEPTION = 'Your submission failed due to an unexpected communications error - please try again later';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form['submission_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submission URL'),
      '#description' => $this->t('The MessageFocus Form URL to which to post this form\'s data.'),
      '#default_value' => (array_key_exists('submission_url', $this->configuration)) ? $this->configuration['submission_url'] : '',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['submission_url'] = $form_state->getValue('submission_url');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
  {

    $messagefocus_data = array();

    // Retrieve original Webform element information

    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $webform_submission->getWebform();

    $form_elements = $webform->getElementsDecoded();


    // Iterate over form elements checking if any have our 'magic' custom mapping key

    foreach ($form_elements as $element_name => $info) {
      if (array_key_exists('#' . webformMessageFocusHandler::MAPPING_KEY, $info)) {
        // We have a MessageFocus field mapping, so add this info to the data array
        $messagefocus_data[$info['#' . webformMessageFocusHandler::MAPPING_KEY]] = $webform_submission->getElementData($element_name);
      }
    }

    // Create a new HTTP client
    $client = \Drupal::httpClient();

    // Get the URL to post the data to
    $post_uri = $this->configuration['submission_url'];

    try {
      // Send the request to MessageFocus
      $response = $client->request('POST', $post_uri, ['form_params' => $messagefocus_data, 'allow_redirects' => false]);

      // Parse the response and check for any errors
      switch ($response->getStatusCode()) {
        case 302:
          break;
        case 200:
        case 400:
        case 401:
        case 403:
        case 404:
        case 422:
        case 500:
        default:
          // An error occurred - this is likely a validation problem but we have to print a generic message as MessageFocus doesn't provide details
          $form_state->setErrorByName('form', $this->t(webformMessageFocusHandler::MESSAGE_SUBMISSION_BAD_RESPONSE_CODE));
          \Drupal::logger('webform_messagefocus')->notice('Error when submitting form data to MessageFocus, HTTP status code %response_code', array('%response_code' => $response->getStatusCode()));
          break;
      }
    } catch (\Exception $ex) {
      $form_state->setErrorByName('form', $this->t(webformMessageFocusHandler::MESSAGE_SUBMISSION_CLIENT_EXCEPTION));
      \Drupal::logger('webform_messagefocus')->error('Exception when submitting form data to MessageFocus, message %message', array('%message' => $ex->getMessage()));
    }
  }
}
