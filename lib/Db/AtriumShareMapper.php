<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * AtriumShareMapper is the data-access layer for atrium_shares. Email lookups
 * compare case-insensitively (LOWER) to match the core's identity binding.
 *
 * @extends QBMapper<AtriumShare>
 */
class AtriumShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'atrium_shares', AtriumShare::class);
	}

	/**
	 * @throws DoesNotExistException when no share has the given id
	 * @throws MultipleObjectsReturnedException
	 */
	public function find(int $id): AtriumShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException when no share has the given token
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByToken(string $token): AtriumShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)));
		return $this->findEntity($qb);
	}

	/**
	 * findByFileId returns every share pinned to the given node, newest first.
	 * Owner scoping and active-only filtering are applied in the service.
	 *
	 * @return AtriumShare[]
	 */
	public function findByFileId(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * findByParentFolder returns every share whose node is a direct child of the
	 * given folder and is owned by $ownerUid, in ONE query (joined to filecache on
	 * the parent) — the batch the share provider runs once per directory listing,
	 * matching the core SharesPlugin's O(1)-queries-per-listing profile.
	 * Active-only filtering is applied by the caller via isActive().
	 *
	 * @return AtriumShare[]
	 */
	public function findByParentFolder(int $parentFileId, string $ownerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->innerJoin('s', 'filecache', 'f', $qb->expr()->eq('f.fileid', 's.file_id'))
			->where($qb->expr()->eq('f.parent', $qb->createNamedParameter($parentFileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.owner_uid', $qb->createNamedParameter($ownerUid, IQueryBuilder::PARAM_STR)));
		return $this->findEntities($qb);
	}

	/**
	 * findByOwner returns every share created by $ownerUid, newest first.
	 * Active-only filtering is applied by the caller via isActive().
	 *
	 * @return AtriumShare[]
	 */
	public function findByOwner(string $ownerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * findByRecipientEmail returns every share for the recipient, newest first.
	 * Active-only filtering (expiry/limit) is applied in the service.
	 *
	 * @return AtriumShare[]
	 */
	public function findByRecipientEmail(string $email): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq(
				$qb->func()->lower('recipient_email'),
				$qb->createNamedParameter(mb_strtolower($email), IQueryBuilder::PARAM_STR),
			))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * deleteRetiredBefore hard-deletes every share whose retention deadline has
	 * passed (an expired share once its expiry is older than $cutoff, an exhausted
	 * one once its last download is). The caller passes $cutoff = now minus the
	 * retention window. Returns the number of rows removed. A raw statement because
	 * the exhausted branch compares two columns (download_count >= max_downloads).
	 */
	public function deleteRetiredBefore(\DateTime $cutoff): int {
		$c = $cutoff->format('Y-m-d H:i:s');
		return $this->db->executeStatement(
			'DELETE FROM `*PREFIX*atrium_shares` WHERE '
			. '(`expires_at` IS NOT NULL AND `expires_at` < ?) '
			. 'OR (`max_downloads` IS NOT NULL AND `download_count` >= `max_downloads` '
			. 'AND `last_download_at` IS NOT NULL AND `last_download_at` < ?)',
			[$c, $c],
		);
	}
}
