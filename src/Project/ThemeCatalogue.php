<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use PDO;
use InvalidArgumentException;
final class ThemeCatalogue {
    public function __construct(private readonly PDO $db) {}
    public function select(string $project,string $theme): void {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$project)) throw new InvalidArgumentException('Projet invalide');
        if ($theme==='') {
            $this->db->prepare('DELETE FROM dm_project_theme WHERE project_id=? AND installed_sha IS NULL')->execute([$project]);
            return;
        }
        $q=$this->db->prepare('SELECT id FROM dm_theme WHERE id=?');$q->execute([$theme]);
        if ($q->fetchColumn()===false) throw new InvalidArgumentException('Charte inconnue');
        $this->db->prepare('INSERT INTO dm_project_theme(project_id,theme_id) VALUES (?,?) ON DUPLICATE KEY UPDATE theme_id=IF(installed_sha IS NULL,VALUES(theme_id),theme_id)')->execute([$project,$theme]);
    }
}
