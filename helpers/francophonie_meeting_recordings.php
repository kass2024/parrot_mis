<?php
declare(strict_types=1);

require_once __DIR__ . '/francophonie_meeting_invitation_schema.php';
require_once __DIR__ . '/zoom_meeting_api.php';

/**
 * @return array<string, array<string, mixed>>
 */
function fm_meeting_invitations_by_zoom_number(mysqli $conn): array
{
    fm_meeting_ensure_schema($conn);

    $map = [];
    $res = $conn->query(
        "SELECT id, topic, start_time, duration_minutes, zoom_meeting_number, zoom_password
         FROM francophonie_mobility_meeting_invitations
         WHERE zoom_meeting_number IS NOT NULL AND zoom_meeting_number <> ''"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $num = preg_replace('/\D+/', '', (string) ($row['zoom_meeting_number'] ?? ''));
            if ($num === '') {
                continue;
            }
            $map[$num] = $row;
        }
        $res->free();
    }

    return $map;
}

/**
 * @return list<array<string, mixed>>
 */
function fm_meeting_pick_recording_play_url(array $recordingFiles): array
{
    $playUrl = '';
    $downloadUrl = '';
    $fileTypes = [];

    foreach ($recordingFiles as $file) {
        if (!is_array($file)) {
            continue;
        }
        $type = strtoupper((string) ($file['file_type'] ?? ''));
        if ($type !== '') {
            $fileTypes[] = $type;
        }
        if ($playUrl === '' && !empty($file['play_url'])) {
            $playUrl = (string) $file['play_url'];
        }
        if ($downloadUrl === '' && !empty($file['download_url']) && $type === 'MP4') {
            $downloadUrl = (string) $file['download_url'];
        }
    }

    return [
        'play_url' => $playUrl,
        'download_url' => $downloadUrl,
        'file_types' => array_values(array_unique($fileTypes)),
    ];
}

/**
 * @return array{items: list<array<string, mixed>>, total: int, next_page_token: string|null}
 */
function fm_meeting_fetch_cloud_recordings(
    mysqli $conn,
    string $dateFrom,
    string $dateTo,
    string $search = '',
    ?string $pageToken = null
): array {
    $known = fm_meeting_invitations_by_zoom_number($conn);
    if ($known === []) {
        return ['items' => [], 'total' => 0, 'next_page_token' => null];
    }

    $searchTrim = trim($search);
    $zoomSearch = $searchTrim !== '' ? $searchTrim : null;

    $allMeetings = [];
    $pageToken = $pageToken;
    $pages = 0;
    do {
        $cloud = zoom_api_list_user_recordings($dateFrom, $dateTo, $zoomSearch, $pageToken);
        foreach ($cloud['meetings'] as $meeting) {
            if (is_array($meeting)) {
                $allMeetings[] = $meeting;
            }
        }
        $pageToken = $cloud['next_page_token'];
        $pages++;
    } while ($pageToken !== null && $pages < 12);

    $items = [];
    $searchLower = mb_strtolower($searchTrim);

    foreach ($allMeetings as $meeting) {
        if (!is_array($meeting)) {
            continue;
        }

        $meetingNumber = preg_replace('/\D+/', '', (string) ($meeting['id'] ?? ''));
        if ($meetingNumber === '' || !isset($known[$meetingNumber])) {
            continue;
        }

        $inv = $known[$meetingNumber];
        $topic = trim((string) ($inv['topic'] ?? ''));
        if ($topic === '') {
            $topic = trim((string) ($meeting['topic'] ?? ''));
        }

        $startTime = (string) ($meeting['start_time'] ?? ($inv['start_time'] ?? ''));
        $startTs = strtotime($startTime);
        $startDisplay = $startTs ? date('M j, Y g:i A', $startTs) : $startTime;
        $startDate = $startTs ? date('Y-m-d', $startTs) : '';

        if ($searchTrim !== '') {
            $haystack = mb_strtolower(implode(' ', [
                $topic,
                $meetingNumber,
                (string) ($inv['id'] ?? ''),
                $startDisplay,
                $startDate,
            ]));
            if (!str_contains($haystack, $searchLower)) {
                continue;
            }
        }

        $files = is_array($meeting['recording_files'] ?? null) ? $meeting['recording_files'] : [];
        $media = fm_meeting_pick_recording_play_url($files);
        $totalSize = 0;
        foreach ($files as $file) {
            if (is_array($file)) {
                $totalSize += (int) ($file['file_size'] ?? 0);
            }
        }

        $items[] = [
            'invitation_id' => (int) ($inv['id'] ?? 0),
            'topic' => $topic,
            'meeting_number' => $meetingNumber,
            'meeting_uuid' => (string) ($meeting['uuid'] ?? ''),
            'start_time' => $startTime,
            'start_time_display' => $startDisplay,
            'start_date' => $startDate,
            'duration_minutes' => (int) ($meeting['duration'] ?? ($inv['duration_minutes'] ?? 0)),
            'recording_files_count' => count($files),
            'total_size_bytes' => $totalSize,
            'total_size_label' => fm_meeting_format_bytes($totalSize),
            'play_url' => $media['play_url'],
            'download_url' => $media['download_url'],
            'file_types' => $media['file_types'],
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['start_time'] ?? ''), (string) ($a['start_time'] ?? ''));
    });

    return [
        'items' => $items,
        'total' => count($items),
        'next_page_token' => null,
    ];
}

function fm_meeting_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    return round($bytes / 1073741824, 2) . ' GB';
}
