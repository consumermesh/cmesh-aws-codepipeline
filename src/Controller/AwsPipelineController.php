<?php

namespace Drupal\cmesh_aws_pipeline\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for AWS Pipeline API endpoints.
 */
class AwsPipelineController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AwsPipelineController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * API endpoint to trigger an AWS CodePipeline execution.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object with optional JSON body.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with execution result.
   */
  public function trigger(Request $request) {
    $content = json_decode($request->getContent(), TRUE) ?? [];

    // Get configuration
    $config = $this->configFactory->get('cmesh_aws_pipeline.contentpush');
    $aws_pipeline_name = $content['pipeline_name'] ?? $config->get('aws_pipeline_name');
    $aws_region = $content['region'] ?? $config->get('aws_region');

    if (empty($aws_pipeline_name) || empty($aws_region)) {
      return new JsonResponse([
        'error' => 'Pipeline name and region must be configured or provided in request',
      ], 400);
    }

    try {
      $client = new \Aws\CodePipeline\CodePipelineClient([
        'region' => $aws_region,
        'version' => 'latest'
      ]);

      $result = $client->startPipelineExecution([
        'name' => $aws_pipeline_name
      ]);

      $executionId = $result['pipelineExecutionId'] ?? NULL;

      \Drupal::logger('cmesh_aws_pipeline')->info(
        'Pipeline triggered via API. Pipeline: @pipeline, Execution ID: @executionId',
        ['@pipeline' => $aws_pipeline_name, '@executionId' => $executionId]
      );

      // Store execution ID in state for status polling.
      \Drupal::state()->set('cmesh_aws_pipeline.current_execution', [
        'execution_id' => $executionId,
        'pipeline_name' => $aws_pipeline_name,
        'region' => $aws_region,
        'started' => time(),
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'process_id' => $executionId,
      ]);

    } catch (\Aws\Exception\AwsException $e) {
      \Drupal::logger('cmesh_aws_pipeline')->error(
        'Failed to trigger pipeline via API: @message',
        ['@message' => $e->getMessage()]
      );

      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * API endpoint to get pipeline execution status.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status information.
   */
  public function status(Request $request) {
    $current = \Drupal::state()->get('cmesh_aws_pipeline.current_execution');
    if (!$current) {
      return new JsonResponse([
        'is_running' => FALSE,
        'completed' => NULL,
      ]);
    }

    $config = $this->configFactory->get('cmesh_aws_pipeline.contentpush');
    $aws_pipeline_name = $current['pipeline_name'] ?? $config->get('aws_pipeline_name');
    $aws_region = $current['region'] ?? $config->get('aws_region');
    $execution_id = $current['execution_id'];

    try {
      $client = new \Aws\CodePipeline\CodePipelineClient([
        'region' => $aws_region,
        'version' => 'latest',
      ]);

      $result = $client->getPipelineExecution([
        'pipelineName' => $aws_pipeline_name,
        'pipelineExecutionId' => $execution_id,
      ]);

      $status = $result['pipelineExecution']['status'] ?? 'Unknown';
      $is_running = in_array($status, ['InProgress', 'Stopping']);

      $completed = NULL;
      if (!$is_running) {
        $completed = time();
        \Drupal::state()->delete('cmesh_aws_pipeline.current_execution');
      }

      return new JsonResponse([
        'is_running' => $is_running,
        'completed' => $completed,
        'started' => $current['started'] ?? NULL,
        'output' => "Pipeline status: $status",
      ]);

    } catch (\Aws\Exception\AwsException $e) {
      \Drupal::logger('cmesh_aws_pipeline')->error(
        'Failed to get pipeline status: @message',
        ['@message' => $e->getMessage()]
      );

      \Drupal::state()->delete('cmesh_aws_pipeline.current_execution');
      return new JsonResponse([
        'is_running' => FALSE,
        'completed' => NULL,
        'output' => $e->getMessage(),
      ]);
    }
  }

  /**
   * API endpoint to list available environments.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with environments array.
   */
  public function pipelines() {
    $config = $this->configFactory->get('cmesh_aws_pipeline.contentpush');
    $pipeline_name = $config->get('aws_pipeline_name') ?? 'default';

    return new JsonResponse([
      [
        'env' => 'default',
        'commands' => [
          [
            'command_key' => 'default',
            'label' => "Deploy ($pipeline_name)",
          ],
        ],
      ],
    ]);
  }

}
