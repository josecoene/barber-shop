provider "aws" {
  region = var.region

  # Tags que vão em todo recurso que aceita tags (Project, Environment, ManagedBy e Course).
  # Ficam em local.tags, no locals.tf.
  default_tags {
    tags = local.tags
  }
}
