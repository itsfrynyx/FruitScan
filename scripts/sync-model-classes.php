<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../server/bootstrap.php';
$fruits=model_config()['fruit_classes']??[];
if (!$fruits) throw new RuntimeException('Model has no fruit classes.');
db()->beginTransaction();
try {
    foreach ($fruits as $fruit) {
        $id=query('SELECT id FROM fruit_classes WHERE LOWER(name)=?',[$fruit])->fetchColumn();
        if ($id!==false) query("UPDATE fruit_classes SET name=?,status='active' WHERE id=?",[$fruit,$id]);
        else query("INSERT INTO fruit_classes(name,description,status) VALUES(?,?,'active')",[$fruit,"Fresh and rotten $fruit images are included in the trained classifier. Results describe visible condition only."]);
    }
    query("UPDATE fruit_classes SET status='inactive' WHERE name NOT IN (".implode(',',array_fill(0,count($fruits),'?')).')',$fruits);
    db()->commit();
} catch (Throwable $error) { db()->rollBack(); throw $error; }
echo "Model categories synchronized with the configured database.\n";
