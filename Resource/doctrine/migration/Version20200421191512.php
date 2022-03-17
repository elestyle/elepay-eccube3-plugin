<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolsException;
use Eccube\Application;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20200421191512 extends AbstractMigration
{
    protected $tables = array();

    protected $entities = array();

    public function __construct()
    {
        $this->tables = array(
            'plg_elepay_config',
            'plg_elepay_order'
        );

        $this->entities = array(
            'Plugin\Elepay\Entity\ElepayConfig',
            'Plugin\Elepay\Entity\ElepayOrder'
        );
    }

    /**
     * @param Schema $schema
     * @throws ToolsException
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $app = Application::getInstance();
        $em = $app['orm.em'];
        $classes = array();
        foreach ($this->entities as $entity) {
            $classes[] = $em->getMetadataFactory()->getMetadataFor($entity);
        }

        $tool = new SchemaTool($em);
        $tool->createSchema($classes);
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        foreach ($this->tables as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
