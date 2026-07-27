<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * AtriumUploadMapper is the data-access layer for atrium_uploads. Email lookups
 * compare case-insensitively (LOWER), matching the recipient-identity binding
 * used everywhere else.
 *
 * @extends QBMapper<AtriumUpload>
 */
class AtriumUploadMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'atrium_uploads', AtriumUpload::class);
	}

	/**
	 * findByFileIds returns the upload records for the given node ids in ONE query
	 * (the folder-listing service resolves uploader and original name without a
	 * per-node query). An empty input yields an empty array.
	 *
	 * @param int[] $fileIds
	 * @return AtriumUpload[]
	 */
	public function findByFileIds(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * findOwnUpload returns the recipient's existing upload of $originalName in the
	 * share, or null. Matching on the uploader means one recipient can never target
	 * another's file.
	 *
	 * @throws MultipleObjectsReturnedException should never occur (name is unique per uploader+share)
	 */
	public function findOwnUpload(int $shareId, string $uploaderEmail, string $originalName): ?AtriumUpload {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq(
				$qb->func()->lower('uploader_email'),
				$qb->createNamedParameter(mb_strtolower($uploaderEmail), IQueryBuilder::PARAM_STR),
			))
			->andWhere($qb->expr()->eq('original_name', $qb->createNamedParameter($originalName, IQueryBuilder::PARAM_STR)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * deleteByFileId removes the record for a node, so a listing never surfaces a
	 * dangling upload after its file was removed.
	 */
	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
