export type VisitStage =
  'booked' | 'waiting' | 'serving' | 'laboratory' | 'pharmacy' | 'completed' | 'no_show';

/** What a family companion may see: progress and location only. */
export interface VisitProgress {
  ticket: string;
  clinic: string;
  room: string | null;
  stage: VisitStage;
  ahead: number;
  waitRange: string | null;
  serving: string | null;
  pharmacy: {
    status: 'received' | 'preparing' | 'checking' | 'ready' | 'completed';
    label: string;
    pickupNumber: string;
  } | null;
  syncedAt: string | null;
  patientName: string;
}
export interface Visit extends VisitProgress {
  doctor: string;
  dateLabel: string;
  time: string;
  isToday: boolean;
  checkInCode: string;
  checkInBy: string | null;
  leaveBy: string | null;
  leadMinutes: number;
  travelMinutes: number;
  companionActive: boolean;
}

declare module '@inertiajs/core' {
  export interface InertiaConfig {
    sharedPageProps: {
      hospital: string;
      demo: boolean;
      pollSeconds: number;
      today: string;
      now: string;
      auth: {
        role: 'patient' | 'staff' | 'manager';
        name: string;
        phone: string | null;
        unit: string | null;
      } | null;
      visit: Visit | null;
      unread: number;
    };
    flashDataType: { status?: string; error?: string; shareLink?: string };
  }
}
