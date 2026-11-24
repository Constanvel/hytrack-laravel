export type VisitStage =
  | 'booked'
  | 'waiting'
  | 'serving'
  | 'laboratory'
  | 'pharmacy'
  | 'completed'
  | 'no_show'
  | 'cancelled';

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
  /** Signed payload drawn as the check-in QR (App\Support\CheckInTicket). */
  checkInQr: string;
  /** Server time staff confirmed the arrival, "09.12". */
  checkedInAt: string | null;
  /** Set when staff cancelled the booking. */
  cancelReason: string | null;
  checkInBy: string | null;
  leaveBy: string | null;
  leadMinutes: number;
  travelMinutes: number;
  companionActive: boolean;
  laboratoryDone: boolean;
}
/** What an accepted companion sees (VisitView::forCompanion, Figma 27:303). */
export interface CompanionProgress extends VisitProgress {
  phone: string | null;
  checkedInAt: string | null;
  place: string;
  placeDetail: string | null;
  timeline: { label: string; time: string | null; current: boolean }[];
  next: string;
  /** "Hari ini" or the service date, and the doctor's session "08.00–12.00". */
  day: string;
  schedule: string;
  endsAt: string;
}
/** A ticket identified by the staff scanner, before check-in is confirmed. */
export interface ScannedTicket {
  /** booked: may be confirmed · checked_in: already recorded · no_show: unusable. */
  state: 'booked' | 'checked_in' | 'no_show';
  checkedInAt: string | null;
  checkedInBy: string | null;
  code: string;
  name: string;
  ticket: string;
  clinic: string;
  doctor: string;
  schedule: string;
  date: string;
  deadline: string | null;
}

declare module '@inertiajs/core' {
  export interface InertiaConfig {
    sharedPageProps: {
      hospital: string;
      demo: boolean;
      pollSeconds: number;
      today: string;
      now: string;
      /** Server time of the response, "09.12.45": the last successful sync. */
      syncedAt: string;
      auth: {
        role: 'patient' | 'staff' | 'manager';
        name: string;
        phone: string | null;
        unit: string | null;
      } | null;
      visit: Visit | null;
      unread: number;
      /** Visits this account accompanies right now. */
      following: number;
    };
    flashDataType: { status?: string; error?: string; shareLink?: string; scan?: ScannedTicket };
  }
}
