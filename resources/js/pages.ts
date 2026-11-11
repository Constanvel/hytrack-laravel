import type { ComponentType } from 'react';
import { Login } from '@/features/auth/login';
import { ErrorPage } from '@/features/errors/error-page';
import { CheckInGuide, Directions, Help, Notifications, QueueGuide } from '@/features/help/help';
import { Hya } from '@/features/help/hya';
import { Management } from '@/features/operations/management';
import { Staff } from '@/features/operations/staff';
import { Clinics, DoctorSchedule } from '@/features/patient/booking';
import { CompanionAccess, CompanionView } from '@/features/patient/companion';
import { Account, Home } from '@/features/patient/home';
import { CheckIn, Visit } from '@/features/patient/visit';
import './types';

/**
 * Component names used by Laravel (Inertia::render / Route::inertia in routes/web.php).
 * This replaces the catch-all route switch of the previous Next.js app.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export const pages: Record<string, ComponentType<any>> = {
  Login,
  ErrorPage,
  Home,
  Account,
  Clinics,
  DoctorSchedule,
  Visit,
  CheckIn,
  CompanionAccess,
  CompanionView,
  Hya,
  Help,
  CheckInGuide,
  QueueGuide,
  Directions,
  Notifications,
  Staff,
  Management,
};
