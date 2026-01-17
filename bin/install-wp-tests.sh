#!/usr/bin/env bash
# Install WordPress PHPUnit test suite to WP_TESTS_DIR alongside a local WP core checkout.
# Usage: bin/install-wp-tests.sh <db_name> <db_user> <db_pass> <db_host> <wp_version> <test_lib_dir>
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}
WP_TESTS_DIR=${6:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

echo "Installing WordPress test suite into ${WP_TESTS_DIR} (WP ${WP_VERSION}) with core at ${WP_CORE_DIR}"

ensure_mysql_client() {
  if ! command -v mysql >/dev/null 2>&1; then
    echo "mysql client missing; installing..."
    sudo apt-get update
    sudo apt-get install -y default-mysql-client
  fi
}

ensure_svn() {
  if ! command -v svn >/dev/null 2>&1; then
    echo "svn not found; installing..."
    sudo apt-get update
    sudo apt-get install -y subversion
  fi
}

download_wp() {
  if [ -f "${WP_CORE_DIR}/wp-settings.php" ]; then
    return
  fi

  mkdir -p "${WP_CORE_DIR}"

  local version_tag="${WP_VERSION}"
  if [ "${WP_VERSION}" = "latest" ]; then
    version_tag="latest"
  fi

  echo "Downloading WordPress core (${version_tag})..."
  local tarball="/tmp/wordpress-${version_tag}.tar.gz"
  curl -sSL "https://wordpress.org/wordpress-${version_tag}.tar.gz" -o "$tarball"
  tar -xzf "$tarball" -C "${WP_CORE_DIR%/*}"
  if [ "${WP_CORE_DIR}" != "${WP_CORE_DIR%/*}/wordpress" ]; then
    mv "${WP_CORE_DIR%/*}/wordpress"/* "${WP_CORE_DIR}"
    rm -rf "${WP_CORE_DIR%/*}/wordpress"
  fi
}

download_tests() {
  if [ -f "${WP_TESTS_DIR}/includes/functions.php" ]; then
    return
  fi

  mkdir -p "${WP_TESTS_DIR}"

  local tests_tag="trunk"
  if [ "${WP_VERSION}" != "latest" ]; then
    tests_tag="tags/${WP_VERSION}"
  fi

  echo "Fetching WordPress test suite from develop.svn.wordpress.org/${tests_tag}..."
  ensure_svn
  svn export --force --quiet "https://develop.svn.wordpress.org/${tests_tag}/tests/phpunit" "${WP_TESTS_DIR}"
}

write_config() {
  cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<PHP
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );

// Path to the WordPress codebase under test.
define( 'ABSPATH', '${WP_CORE_DIR}/' );

// Allow multiple test runs to coexist.
define( 'WP_TESTS_TABLE_PREFIX', 'wptests_' );

define( 'WP_DEBUG', true );
PHP
}

create_db() {
  mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" -e "CREATE DATABASE IF NOT EXISTS \"${DB_NAME}\";" || true
}

ensure_mysql_client
download_wp
download_tests
write_config
create_db

echo "WP tests installed to ${WP_TESTS_DIR} with core at ${WP_CORE_DIR}"