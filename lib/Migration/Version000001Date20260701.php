<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for Atrium Secureshare.
 *
 * `atrium_shares` backs identity-bound external shares; `atrium_uploads` tracks
 * every file a recipient uploads into a folder share, the source of truth for two
 * folder-mode rules the Nextcloud node itself cannot express: Write/Read-Own
 * visibility (matched by share_id + uploader_email) and stable display names
 * (original_name survives a physical collision rename).
 *
 * Each createTable is hasTable-guarded so the disable/enable upgrade cycle the
 * deploy uses is safe to repeat.
 */
class Version000001Date20260701 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('atrium_shares')) {
			$table = $schema->createTable('atrium_shares');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// Unguessable capability handed to the core as the external share id.
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('recipient_email', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('permissions', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('max_downloads', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('download_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			// Instant of the last counted download; for a capped share this is the
			// moment it became exhausted (counting stops at the cap).
			$table->addColumn('last_download_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('email_sent', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

			$table->setPrimaryKey(['id']);
			// Index names must stay <= 30 chars (Nextcloud/Doctrine constraint).
			$table->addUniqueIndex(['token'], 'atrium_share_token_idx');
			$table->addIndex(['file_id'], 'atrium_share_file_idx');
			$table->addIndex(['recipient_email'], 'atrium_share_email_idx');
			$table->addIndex(['expires_at'], 'atrium_share_expiry_idx');

			$changed = true;
		}

		if (!$schema->hasTable('atrium_uploads')) {
			$table = $schema->createTable('atrium_uploads');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// The numeric atrium_shares.id (not the token).
			$table->addColumn('share_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uploader_email', Types::STRING, ['length' => 255, 'notnull' => true]);
			// Shown back verbatim regardless of the physical (possibly suffixed) name.
			$table->addColumn('original_name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('uploaded_at', Types::DATETIME, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			// Index names must stay <= 30 chars (Nextcloud/Doctrine constraint).
			// One row per uploaded node: unique so the uploader lookup is a point read.
			$table->addUniqueIndex(['file_id'], 'atrium_upload_file_idx');
			// The own-listing filter and the re-upload lookup both scope by share.
			$table->addIndex(['share_id', 'uploader_email'], 'atrium_upload_own_idx');

			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
