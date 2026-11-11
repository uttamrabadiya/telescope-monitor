export type Capability = 'view' | 'operate' | 'manage'
export type PreferenceGroup = 'general' | 'privacy' | 'retention'
export interface FieldPolicy { locked: boolean; source: string; reason: string | null; min?:number; max?:number }
export interface SettingsGroup {
  group: PreferenceGroup
  values: Record<string, string | number | boolean | string[]>
  fields: Record<string, FieldPolicy>
  version: number
  config_revision: number
}
export interface Check { key: string; label: string; state: 'passed' | 'warning' | 'failed' | 'unverified' | 'running'; kind: string; detail: string; observed_at: string|null; last_started_at?:string|null;last_success_at?:string|null;last_failure_at?:string|null;fresh?:boolean }
export interface Bootstrap {
  application: { display_name: string; dashboard_url: string; timezone: string }
  timezones: string[]
  environment: string
  actor: { label: string | null }
  capabilities: Record<Capability, boolean>
  installation: { id: string;state:string;generation:number; setup: { current_step?: number }; config_revision: number } | null
  storage: string
  features: Record<string, boolean>
  health: Check[]
  capture_status: { source_enabled: boolean; pending: number; failed: number; last_received_at: string | null; last_processed_at: string | null } | null
  assignment_configuration?: {enabled:boolean;requested:boolean;configured:boolean;detail:string}|null
  data_as_of: string
}

export interface CaptureProbe { id: string; state: string; result_type: string | null; result_id: string | null; asynchronous_queue: boolean }

export interface ErrorGroup { id:string; class:string; message:string; file:string; line:number; environment:string; status:string; state_version:number; lifetime_count:string; range_count:string; first_seen_at:string; last_seen_at:string; snoozed_until:string|null; is_test:boolean; recovered_only:boolean; regressions:boolean; origins?:string[];assignee?:Assignee|null }
export interface WindowInfo { range:string; start:string; end:string; as_of:string; bucket_seconds:number; coverage:string }
export interface InboxResult { items:ErrorGroup[]; page:number; per_page:number; total:string; last_page:number; window:WindowInfo; environments:string[]; allowed_tags:string[]; coverage:string }
export interface TrendBucket { at:string; occurrences:string; new_groups:string; regressions:string }
export interface OverviewResult { window:WindowInfo; totals:{occurrences:string;new_groups:string;regressions:string}; buckets:TrendBucket[]; errors:InboxResult; delivery:{state:string;detail:string;observations?:{pending:number;failed:number;needs_review:number;last_accepted_at:string|null}}; activity:{type:string;issue_id:string;summary:string;at:string}[] }
export interface Frame { file:string;line:number;class?:string;function?:string;source_url?:string|null;telescope_url?:string }
export interface Evidence { exception:{class:string;message:string;file:string;line:number;trace:Frame[]}; execution:Record<string,string|number>; logs:Record<string,string|number>[]; queries:Record<string,string|number>[]; timeline:Record<string,string|number>[]; tags:Record<string,string>; coverage:Record<string,string>; source?:{release:string;template:string|null}|null }
export interface SelectedOccurrence { id:string;source_uuid:string;occurred_at:string;origin:string;mode:string;evidence_state:string;evidence:Evidence|null;source_url?:string|null;telescope_url?:string }
export interface DetailResult { issue:ErrorGroup; window:WindowInfo; selected:SelectedOccurrence|null; occurrences:{items:{id:string;at:string;origin:string;mode:string;release:string|null}[];page:number;last_page:number;total:string}; insights:{retained_count:string;from:string|null;to:string|null;coverage:string;breakdowns:{origin:{value:string;count:string}[];route:{value:string;count:string}[];release:{value:string;count:string}[];tags:{value:string;count:string}[];users:number}}; activity:{id:string;type:string;actor:string;at:string;metadata:Record<string,string|null>}[] }

export interface NoteDraft { body:string; baseline:string; id:string|null; version:number; page:number }
export interface NoteItem { id:string;author:string;own:boolean;body:string|null;state:'available'|'deleted'|'unreadable';version:number;created_at:string;edited_at:string|null;deleted_at:string|null;can_edit:boolean;can_delete:boolean }
export interface NotesResult { items:NoteItem[];page:number;last_page:number;total:string }
export interface ViewDefinition { name:string;visibility:'personal'|'shared';filters:Record<string,string|boolean>;sort:string;range:string }
export interface SavedView { id:string;name:string;visibility:'personal'|'shared';owner:string;own:boolean;version:number;filter_schema_version:number;definition:ViewDefinition;state:'ready'|'needs_repair';reason:string|null;can_edit:boolean }
export interface ViewIndex { items:SavedView[];page:number;last_page:number;total:string;builtins:string[] }
export interface ViewPreferences { version:number;shortcuts:{ref:string;favorite:boolean;position:number}[];default_view_ref:string|null;labels?:Record<string,string> }
export interface Assignee { key:string;label:string;state:'available'|'ineligible'|'unavailable' }

export interface EmailDestination {id:string;provider:string;name:string;email:string|null;display:string;enabled:boolean;disconnected:boolean;version:number;state:string;last_test_at:string|null;last_test_outcome:string|null;last_accepted_at:string|null;failed_count:string}
export interface AlertRuleDefinition {name:string;enabled:boolean;events:string[];conditions:{environments:string[];class:string|null;origin:string|null;tag:string|null};connections:string[];threshold_count:number|null;window_seconds:number|null;cooldown_seconds:number}
export interface AlertRuleItem extends AlertRuleDefinition {id:string;version:number;definition_version:number}
export interface AlertConfiguration {rules:AlertRuleItem[];connections:EmailDestination[];events:Record<string,string>;allowed_environments:string[];enabled:boolean;queue_asynchronous:boolean;max_cooldown_seconds:number;mailer:string;allowed_tags:string[];starter:AlertRuleDefinition}
export interface DeliveryItem {id:string;status:string;action:string;event:string;environment:string;issue_id:string|null;destination:string;destination_name:string;channel:string;attempts:number;cycle:number;cycle_attempts:number;eligible_at:string;expires_at:string;lease_until:string|null;sent_at:string|null;completed_at:string|null;created_at:string;reason:string|null;can_retry:boolean;can_review_resend:boolean}
export interface DeliveryDetail {delivery:DeliveryItem;payload:Record<string,unknown>|null;payload_state:string;payload_text:string|null;rules:{id:string;version:number;name:string;conditions:Record<string,unknown>}[];attempts:{sequence:number;cycle:number;started_at:string;completed_at:string|null;outcome:string;status?:number|null;code:string|null;summary:string|null;retry_after_at:string|null}[]}
export interface DeliveryHistoryResult {items:DeliveryItem[];page:number;last_page:number;total:string;as_of:string;acceptance_note:string}
export interface TestDeliveryOperation {id:string;items:DeliveryItem[];queue_asynchronous:boolean}

export interface IntegrationKey {id:string;purpose:string;revision:number;state:string;source:string;retire_after:string|null}
export interface IntegrationConnection {id:string;provider:'slack'|'webhook'|'github';name:string;display:string;target:{display?:string;owner?:string;repo?:string};enabled:boolean;disconnected:boolean;version:number;state:string;options:{events?:string[];automatic?:boolean;environments?:string[];labels?:string[];known_labels?:string[]};keys?:IntegrationKey[];last_test_at:string|null;last_test_outcome:string|null;last_accepted_at:string|null;failed_count:string}
export interface IntegrationConfiguration {events:Record<string,string>;connections:IntegrationConnection[];allowed_environments:string[];credential_references:string[]}
export interface GitHubLink {id:string;connection:string;repository:string;state:string;version:number;number:number|null;url:string|null;remote_state:string|null;checked_at:string|null;operation:string;delivery:string|null}
export interface GitHubPreview {title:string;body:string;labels:string[];repository:string;digest:string;link_version:number}

export interface RetentionPreview {proposed:Record<string,number>;counts:Record<string,{eligible_count:number;at_least:boolean;reduction:boolean}>;limit:number;as_of:string;next_prune:string;preserves:string[];note:string}
export interface SetupStatus {requirements:Record<string,boolean>;can_finish:boolean;complete:boolean;completed_at:string|null;notification_choice:'skip'|'verify'|null;delivery_verified:boolean;delivery_verified_id?:string|null;delivery_destination?:string|null;capture_probe:string|null;active_rules:number;optional:Record<string,boolean>;note:string}
export interface OperationObservation {key:string;label:string;state:Check['state'];last_started_at:string|null;last_success_at:string|null;last_failure_at:string|null;observed_at:string|null;fresh:boolean;freshness_seconds:number;detail:string;history:{id:string;started_at:string;completed_at:string;state:string;counts:Record<string,number>}[]}
export interface PurgeStatus {id:string;state:string;step:string;from_generation:number;to_generation:number;counts:Record<string,number>;started_at:string;completed_at:string|null;error:string|null;source_floor:number;cutoff:string|null}
export interface OperationsStatus {installation:string;state:string;generation:number;schema:{ready:boolean;version:number;missing:string[]};source:{state:string;epoch?:string;checkpoint?:number;floor?:number;maximum?:number;cutoff?:string|null;detail:string};credentials:{state:string;checked:number;unreadable:number;bounded:boolean;detail:string};observations:OperationObservation[];setup:SetupStatus;purge:PurgeStatus|null;retention:SettingsGroup;limits:Record<string,{min:number;max:number}>;as_of:string;commands:Record<string,string>;offline_note:string}
