<?php
namespace Doctrine\Tests\Models\MappedAssociation;

/**
 * @Entity
 * @Table(name="file_folder")
 * @NamedNativeQueries({
 *      @NamedNativeQuery(
 *          name                = "get-class-by-id",
 *          resultSetMapping    = "get-class",
 *          query               = "SELECT content_class from file_folder WHERE id = ?"
 *      )
 * })
 *
 * @SqlResultSetMappings({
 *      @SqlResultSetMapping(
 *          name    = "get-class",
 *          columns = {
 *              @ColumnResult(
 *                  name = "content_class"
 *              )
 *          }
 *      )
 * })
 *
 */
class FileFolder
{
    /**
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     *
     * @var int $id
     */
    private $id;

    /**
     * @Column(type="string", length=128)
     *
     * @var string $title
     */
    private $title;

    /**
     * @OneToOne(targetEntity="AbstractContent", mappedBy="fileFolder", cascade={"all"}, orphanRemoval=true)
     * @MappedAssociation(discriminatorColumn="content_class")
     *
     * @var AbstractContent $content
     */
    private $content;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param AbstractContent $content
     */
    public function setContent(AbstractContent $content)
    {
        $content->setFileFolder($this);
        $this->content = $content;
    }

    /**
     * @return AbstractContent
     */
    public function getContent()
    {
        return $this->content;
    }
}
