<?php
namespace Drupal\cmesh_aws_pipeline\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ContentPushForm.
 */
class ContentPushForm extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames() {
        return [
            'cmesh_aws_pipeline.contentpush',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return 'content_push_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        // check if user is administrator
        $user = \Drupal::currentUser();
        $isAdmin = $user->hasRole('administrator');
        $config = $this->config('cmesh_aws_pipeline.contentpush');
        $aws_pipeline_name = $config->get('aws_pipeline_name');
        $aws_region = $config->get('aws_region');
        if ($isAdmin) {
            $form['aws_pipeline_name'] = [
                '#type' => 'textfield',
                '#title' => $this->t('AWS Pipeline Name'),
                '#default_value' => $aws_pipeline_name,
            ];
            $form['aws_region'] = [
                '#type' => 'textfield',
                '#title' => $this->t('AWS Region'),
                '#default_value' => $aws_region,
            ];
        }

        if (($aws_pipeline_name == null || $aws_region == null) && !$isAdmin) {
            $this->messenger()->addWarning($this->t('Only administrators can configure this module.'));
        }
        if ($aws_pipeline_name != null && $aws_region != null) {
            $form['result_table'] = [
                '#type' => 'table',
                '#caption' => 'Pipeline Runs',
                '#header' =>
                    array($this->t('Pipeline Name'),
                          $this->t('Run Name'), $this->t('State'),
                          $this->t('Result'), $this->t('Created'), $this->t('Finished')),
            ];


            try {
                $client = new \Aws\CodePipeline\CodePipelineClient([
                    'region' => $aws_region,
                    'version' => 'latest'
                ]);

                $resQA = $client->listPipelineExecutions([
                    'maxResults' => 5,
                    'pipelineName' => $aws_pipeline_name
                ]);

                $index = 0;
                foreach ($resQA['pipelineExecutionSummaries'] as $item) {

                    $form['result_table'][$index]['pipeline_name'] = [
                        '#type' => 'item',
                        '#title' => $item['pipelineExecutionId'],
                    ];
                    $form['result_table'][$index]['state'] = [
                        '#type' => 'item',
                        '#title' => $item['status'],
                    ];
                    $form['result_table'][$index]['created'] = [
                        '#type' => 'item',
                        '#title' => isset($item['startTime']) ? \Drupal::service('date.formatter')->format(date_create($item['startTime'])->getTimestamp(), 'custom', 'Y-m-d h:i:s a') : '',
                    ];
                    $form['result_table'][$index]['finished'] = [
                        '#type' => 'item',
                        '#title' => isset($item['lastUpdateTime']) ? \Drupal::service('date.formatter')->format(date_create($item['lastUpdateTime'])->getTimestamp(), 'custom', 'Y-m-d h:i:s a') : '',
                    ];

                    $index++;
                    if ($index > 5) {
                        break;
                    }
                }
            } catch (\Aws\Exception\AwsException $e) {
                \Drupal::logger('cmesh_aws_pipeline')->error('bad response'.$e->getMessage());
            }

            $form['refresh_status'] = [
                '#type' => 'button',
                '#title' => $this->t('Refresh'),
                '#default_value' => $this->t('Refresh'),
            ];
            $form['push_content'] = [
                '#type' => 'radios',
                '#title' => $this->t('Push content'),
                '#default_value' => 0,
                '#options' =>
                    [
                        0 => t('Stage'),
                    ]
            ];
        }
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $user = \Drupal::currentUser();
        $isAdmin = $user->hasRole('administrator');
        $config = $this->config('cmesh_aws_pipeline.contentpush');
        if ($isAdmin) {
            $config->set('aws_pipeline_name', $form_state->getValue('aws_pipeline_name'))->save();
            $config->set('aws_region', $form_state->getValue('aws_region'))->save();
        }
        \Drupal::logger('cmesh_aws_pipeline')->info('Configuration saved.'. ' Pipeline Name: '.$form_state->getValue('aws_pipeline_name').' Region: '.$form_state->getValue('aws_region'));
        if ($form_state->getValue('push_content') >= 0 ) {
            $aws_pipeline_name = $config->get('aws_pipeline_name');
            $aws_region = $config->get('aws_region');
            $client = new \Aws\CodePipeline\CodePipelineClient([
                'region' => $aws_region,
                'version' => 'latest'
            ]);
            try {
                $client->startPipelineExecution([ 'name' => $aws_pipeline_name ]);
                \Drupal::logger('cmesh_aws_pipeline')->info('Push content successful.');
            } catch (\Aws\Exception\AwsException $e) {
                \Drupal::logger('cmesh_aws_pipeline')->error($e->getMessage());
            }
        }
        $this->config('cmesh_aws_pipeline.contentpush')
             ->set('push_content_to_qa', $form_state->getValue('push_content_to_qa'))
             ->save();
    }
}
