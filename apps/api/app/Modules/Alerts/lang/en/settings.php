<?php

return [
    'budget_warn_percent' => ['label' => 'Warning before the budget runs out', 'description' => 'At what share of the monthly budget an email says it is about to run out, for the platform and each live shop.'],
    'failed_runs' => ['label' => 'Failures before an email', 'description' => 'How many times the same work must fail for the same reason within an hour for an email to be sent. A missing key is told the first time.'],
    'stuck_hours' => ['label' => 'A run that did not end', 'description' => 'After how many hours a run that started and did not end counts as stuck.'],
    'queue_minutes' => ['label' => 'A queue that does not move', 'description' => 'How many minutes work may wait in the queue before an email says the queue does not move.'],
    'sync_hours' => ['label' => 'A store not synced', 'description' => 'After how many hours without a successful sync of a live shop an email is sent.'],
    'repeat_hours' => ['label' => 'Reminder of the same problem', 'description' => 'After how many hours a problem that still exists is told again. A budget problem is told once a month for each cap.'],
];
