<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Db;

use OCP\AppFramework\Db\Entity;

/**
 * AtriumUpload records one file a recipient uploaded into a folder share. It
 * keeps the original upload name so the recipient always sees their own name even
 * when a collision forced a different physical name on disk.
 *
 * @method void setShareId(int $shareId)
 * @method int getShareId()
 * @method void setFileId(int $fileId)
 * @method int getFileId()
 * @method void setUploaderEmail(string $uploaderEmail)
 * @method string getUploaderEmail()
 * @method void setOriginalName(string $originalName)
 * @method string getOriginalName()
 * @method void setUploadedAt(\DateTime $uploadedAt)
 * @method \DateTime getUploadedAt()
 */
class AtriumUpload extends Entity {
	protected ?int $shareId = null;
	protected ?int $fileId = null;
	protected ?string $uploaderEmail = null;
	protected ?string $originalName = null;
	protected ?\DateTime $uploadedAt = null;

	public function __construct() {
		$this->addType('shareId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('uploadedAt', 'datetime');
	}
}
