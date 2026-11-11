export type MonitoringFilters = {
  tag?: string;
  correlation_id?: string;
  path?: string;
  status?: string;
};

export type MonitoringEntryType =
  | 'http'
  | 'console'
  | 'sql'
  | 'queue'
  | 'cache'
  | 'schedule'
  | 'tcp'
  | 'grpc'
  | 'events'
  | 'filesystem';

const ENTRY_LABELS: Record<MonitoringEntryType, string> = {
  http: 'HTTP',
  console: 'Console',
  sql: 'SQL',
  queue: 'Queue',
  cache: 'Cache',
  schedule: 'Schedule',
  tcp: 'TCP',
  grpc: 'gRPC',
  events: 'Events',
  filesystem: 'Filesystem',
};

export function entryTypeLabel(type: MonitoringEntryType) {
  return ENTRY_LABELS[type];
}

export const ENTRY_TYPES = Object.keys(ENTRY_LABELS) as MonitoringEntryType[];

async function monitoringGet<T>(
  path: string,
  params: Record<string, string | number | undefined> = {},
): Promise<T> {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') {
      query.set(key, String(value));
    }
  }
  const suffix = query.size > 0 ? `?${query.toString()}` : '';
  const response = await fetch(`/monitoring/api${path}${suffix}`, {
    credentials: 'include',
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(body?.error || `监控 API ${response.status}`);
  }
  return body as T;
}

export function fetchMonitoringOverview(
  page = 1,
  perPage = 20,
  filters: MonitoringFilters = {},
) {
  return monitoringGet<{
    sessions: Record<string, unknown>[];
    counts: Record<string, number>;
    page: number;
    per_page: number;
    total: number;
  }>('/overview', { page, per_page: perPage, ...filters });
}

export function fetchMonitoringEntries(
  type: MonitoringEntryType,
  page = 1,
  perPage = 25,
  filters: MonitoringFilters = {},
) {
  return monitoringGet<{
    items: Record<string, unknown>[];
    total: number;
    page: number;
    per_page: number;
    type: string;
  }>('/entries', { type, page, per_page: perPage, ...filters });
}

export function fetchMonitoringSession(id: number) {
  return monitoringGet<Record<string, unknown>>(`/sessions/${id}`);
}

export function fetchMonitoringCorrelation(correlationId: string) {
  return monitoringGet<{
    correlation_id: string;
    sessions: Record<string, unknown>[];
  }>(`/correlation/${encodeURIComponent(correlationId)}`);
}
