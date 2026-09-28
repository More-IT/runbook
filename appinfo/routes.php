<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'status#status', 'url' => '/api/status', 'verb' => 'GET'],

		['name' => 'template#index', 'url' => '/api/v1/templates', 'verb' => 'GET'],
		['name' => 'template#create', 'url' => '/api/v1/templates', 'verb' => 'POST'],
		['name' => 'template#import', 'url' => '/api/v1/templates/import', 'verb' => 'POST'],
		['name' => 'template#show', 'url' => '/api/v1/templates/{id}', 'verb' => 'GET'],
		['name' => 'template#update', 'url' => '/api/v1/templates/{id}', 'verb' => 'PATCH'],
		['name' => 'template#destroy', 'url' => '/api/v1/templates/{id}', 'verb' => 'DELETE'],
		['name' => 'template#publish', 'url' => '/api/v1/templates/{id}/publish', 'verb' => 'POST'],
		['name' => 'template#archive', 'url' => '/api/v1/templates/{id}/archive', 'verb' => 'POST'],
		['name' => 'template#unarchive', 'url' => '/api/v1/templates/{id}/unarchive', 'verb' => 'POST'],
		['name' => 'template#duplicate', 'url' => '/api/v1/templates/{id}/duplicate', 'verb' => 'POST'],
		['name' => 'template#export', 'url' => '/api/v1/templates/{id}/export', 'verb' => 'GET'],
		['name' => 'template#update_destination', 'url' => '/api/v1/templates/{id}/destination', 'verb' => 'PUT'],
		['name' => 'template#clear_destination', 'url' => '/api/v1/templates/{id}/destination', 'verb' => 'DELETE'],

		['name' => 'section#create', 'url' => '/api/v1/templates/{templateId}/sections', 'verb' => 'POST'],
		['name' => 'section#update', 'url' => '/api/v1/sections/{id}', 'verb' => 'PATCH'],
		['name' => 'section#destroy', 'url' => '/api/v1/sections/{id}', 'verb' => 'DELETE'],
		['name' => 'section#reorder', 'url' => '/api/v1/sections/{id}/reorder', 'verb' => 'POST'],

		['name' => 'step#create', 'url' => '/api/v1/sections/{sectionId}/steps', 'verb' => 'POST'],
		['name' => 'step#update', 'url' => '/api/v1/steps/{id}', 'verb' => 'PATCH'],
		['name' => 'step#destroy', 'url' => '/api/v1/steps/{id}', 'verb' => 'DELETE'],
		['name' => 'step#reorder', 'url' => '/api/v1/steps/{id}/reorder', 'verb' => 'POST'],

		['name' => 'acl#index', 'url' => '/api/v1/templates/{id}/acl', 'verb' => 'GET'],
		['name' => 'acl#update', 'url' => '/api/v1/templates/{id}/acl', 'verb' => 'PUT'],
		['name' => 'principal#index', 'url' => '/api/v1/principals', 'verb' => 'GET'],

		['name' => 'run#index', 'url' => '/api/v1/runs', 'verb' => 'GET'],
		['name' => 'run#create', 'url' => '/api/v1/templates/{templateId}/runs', 'verb' => 'POST'],
		['name' => 'run#show', 'url' => '/api/v1/runs/{id}', 'verb' => 'GET'],
		['name' => 'run#complete', 'url' => '/api/v1/runs/{id}/complete', 'verb' => 'POST'],
		['name' => 'run#cancel', 'url' => '/api/v1/runs/{id}/cancel', 'verb' => 'POST'],
		['name' => 'run#reopen', 'url' => '/api/v1/runs/{id}/reopen', 'verb' => 'POST'],
		['name' => 'run#destroy', 'url' => '/api/v1/runs/{id}', 'verb' => 'DELETE'],

		['name' => 'run_step#start', 'url' => '/api/v1/run-steps/{id}/start', 'verb' => 'POST'],
		['name' => 'run_step#update', 'url' => '/api/v1/run-steps/{id}', 'verb' => 'PATCH'],
		['name' => 'run_step#complete', 'url' => '/api/v1/run-steps/{id}/complete', 'verb' => 'POST'],
		['name' => 'run_step#skip', 'url' => '/api/v1/run-steps/{id}/skip', 'verb' => 'POST'],
		['name' => 'run_step#reopen', 'url' => '/api/v1/run-steps/{id}/reopen', 'verb' => 'POST'],
		['name' => 'run_step#returnStep', 'url' => '/api/v1/run-steps/{id}/return', 'verb' => 'POST'],

		['name' => 'run_section#update', 'url' => '/api/v1/run-sections/{id}', 'verb' => 'PATCH'],
		['name' => 'run_section#returnSection', 'url' => '/api/v1/run-sections/{id}/return', 'verb' => 'POST'],

		['name' => 'run_acl#index', 'url' => '/api/v1/runs/{id}/acl', 'verb' => 'GET'],
		['name' => 'run_acl#update', 'url' => '/api/v1/runs/{id}/acl', 'verb' => 'PUT'],

		['name' => 'comment#index', 'url' => '/api/v1/runs/{id}/comments', 'verb' => 'GET'],
		['name' => 'comment#create', 'url' => '/api/v1/runs/{id}/comments', 'verb' => 'POST'],
		['name' => 'comment#update', 'url' => '/api/v1/comments/{id}', 'verb' => 'PATCH'],
		['name' => 'comment#destroy', 'url' => '/api/v1/comments/{id}', 'verb' => 'DELETE'],

		['name' => 'attachment#index', 'url' => '/api/v1/runs/{id}/attachments', 'verb' => 'GET'],
		['name' => 'attachment#create', 'url' => '/api/v1/run-steps/{id}/attachments', 'verb' => 'POST'],
		['name' => 'attachment#copy', 'url' => '/api/v1/run-steps/{id}/attachments/copy', 'verb' => 'POST'],
		['name' => 'attachment#show', 'url' => '/api/v1/attachments/{id}', 'verb' => 'GET'],
		['name' => 'attachment#destroy', 'url' => '/api/v1/attachments/{id}', 'verb' => 'DELETE'],

		['name' => 'activity#index', 'url' => '/api/v1/runs/{id}/activity', 'verb' => 'GET'],

		['name' => 'admin_settings#index', 'url' => '/api/v1/admin/settings', 'verb' => 'GET'],
		['name' => 'admin_settings#update', 'url' => '/api/v1/admin/settings', 'verb' => 'PUT'],
		['name' => 'admin_settings#update_destination', 'url' => '/api/v1/admin/settings/destination', 'verb' => 'PUT'],
		['name' => 'admin_settings#clear_destination', 'url' => '/api/v1/admin/settings/destination', 'verb' => 'DELETE'],
		['name' => 'admin_settings#features', 'url' => '/api/v1/features', 'verb' => 'GET'],

		['name' => 'migration#index', 'url' => '/api/v1/admin/migration', 'verb' => 'GET'],
		['name' => 'migration#run', 'url' => '/api/v1/admin/migration', 'verb' => 'POST'],

		['name' => 'work#my_work', 'url' => '/api/v1/my-work', 'verb' => 'GET'],
		['name' => 'work#overview', 'url' => '/api/v1/overview', 'verb' => 'GET'],
	],
];
