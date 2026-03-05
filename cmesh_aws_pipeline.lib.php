<?php
use Drupal\Component\Serialization\Yaml;

function read_module_file($relative_path) {
  $module_handler = \Drupal::service('module_handler');
  $module_path = $module_handler->getModule('cmesh_aws_pipeline')->getPath();
  $file_path = $module_path . '/config/install/' . $relative_path;
  // Get the file system service.
  $file_system = \Drupal::service('file_system');

  // Convert the Drupal path to a real system path.
  $real_path = $file_system->realpath($file_path);

  // Read the contents of the file.
  $content = file_get_contents($real_path);
  $yaml = Yaml::decode($content);
  // Return the contents.
  \Drupal::logger('cmesh_aws_pipeline')->notice(Yaml::encode($yaml));
  return $yaml;
}
