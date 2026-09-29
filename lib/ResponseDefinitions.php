<?php

declare(strict_types=1);

namespace OCA\Runbook;

/**
 * OpenAPI response definitions consumed by nextcloud/openapi-extractor.
 *
 * @psalm-type RunbookAclData = array{id: int, templateId: int, principalType: string, principalId: string, role: string, createdAt: int, updatedAt: int}
 * @psalm-type RunbookActivityData = array{id: int, runId: int, stepId: int|null, actorUid: string, eventType: string, metadata: array<string, mixed>, createdAt: int}
 * @psalm-type RunbookAttachmentData = array{id: int, uuid: string, runId: int, stepId: int, uploaderUid: string, filename: string, mimeType: string, size: int, checksum: string, createdAt: int}
 * @psalm-type RunbookAttachmentDataWithState = array{id: int, uuid: string, runId: int, stepId: int, uploaderUid: string, filename: string, mimeType: string, size: int, checksum: string, createdAt: int, fileState: string}
 * @psalm-type RunbookCommentData = array{id: int, uuid: string, runId: int, stepId: int|null, authorUid: string, body: string, createdAt: int, updatedAt: int}
 * @psalm-type RunbookRunAclData = array{id: int, runId: int, principalType: string, principalId: string, role: string, createdAt: int, updatedAt: int}
 * @psalm-type RunbookRunData = array{id: int, uuid: string, templateId: int|null, templateVersion: int, title: string, description: string, owner: string, status: string, createdAt: int, startedAt: int, completedAt: int|null, cancelledAt: int|null, reopenedAt: int|null, dueAt: int|null, completedBy: string|null, cancelledBy: string|null, updatedAt: int}
 * @psalm-type RunbookRunSectionData = array{id: int, runId: int, sourceSectionId: int|null, title: string, description: string, notes: string, position: int, dependsOn: list<int>, condition: array<string, mixed>|null, conditions: list<array<string, mixed>>}
 * @psalm-type RunbookRunStepData = array{id: int, runSectionId: int, sourceStepId: int|null, uuid: string, title: string, description: string, type: string, required: bool, position: int, config: array<string, mixed>, status: string, response: bool|int|float|string|null, skipReason: string|null, assigneeType: string|null, assigneeId: string|null, dueAt: int|null, startedAt: int|null, completedAt: int|null, skippedAt: int|null, reopenedAt: int|null}
 * @psalm-type RunbookSectionData = array{id: int, templateId: int, title: string, description: string, notes: string, position: int, dependsOn: list<int>, condition: array<string, mixed>|null, conditions: list<array<string, mixed>>}
 * @psalm-type RunbookStepData = array{id: int, sectionId: int, uuid: string, title: string, description: string, type: string, required: bool, position: int, config: array<string, mixed>, defaultAssignee: string|null, dueOffset: string|null}
 * @psalm-type RunbookTemplateData = array{id: int, uuid: string, title: string, description: string, version: int, status: string, owner: string, createdAt: int, updatedAt: int, publishedAt: int|null, archivedAt: int|null}
 * @psalm-type RunbookCommentItemData = array{comment: RunbookCommentData, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>}
 * @psalm-type RunbookWorkItemData = array{runId: int, runTitle: string, runStatus: string, runDueAt: int|null, stepId: int, stepTitle: string, stepStatus: string, stepType: string, assigneeType: string|null, assigneeId: string|null, dueAt: int|null, overdue: bool, dueToday: bool, sectionTitle: string, required: bool}
 * @psalm-type RunbookExportSection = array{ref: string, title: string, description: string, notes: string, dependsOn: list<string>, conditions: list<array{stepRef: string, operator: string, value?: mixed}>}
 * @psalm-type RunbookExportStep = array{ref: string, sectionRef: string, title: string, description: string, type: string, required: bool, position: int, config: object, defaultAssignee: string|null, dueOffset: string|null}
 * @psalm-type RunbookExportDocument = array{format: string, schemaVersion: int, template: array{title: string, description: string}, sections: list<RunbookExportSection>, steps: list<RunbookExportStep>}
 */
class ResponseDefinitions {
}
