<?php

declare(strict_types=1);

namespace App\Models\Notifications;

#[\Database\Annotation\Table('admin_failure_events')]
final class FailureEvent extends \Loom\Model
{
    /** 调用方在同一事务中提交失败记录及其发件箱记录。 */
    public static function unreportedJobs(\Loom\Database $database): array
    {
        return $database->query("SELECT id FROM loom_jobs WHERE state='failed' AND kind<>'notification.push' AND id NOT IN (SELECT job_id FROM admin_failure_events) ORDER BY id LIMIT 100 FOR UPDATE SKIP LOCKED");

    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('job_id', false)]
    public int $job_id;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
