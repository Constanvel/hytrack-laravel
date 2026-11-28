import type { ComponentType } from 'react';
import { InternalLogin } from '@/features/auth/internal-login';
import { Login } from '@/features/auth/login';
import { Register } from '@/features/auth/register';
import { RegisterOtp, RegisterVerified } from '@/features/auth/verify';
import { ErrorPage } from '@/features/errors/error-page';
import { CheckInGuide, Directions, Help, Notifications, QueueGuide } from '@/features/help/help';
import { Hya } from '@/features/help/hya';
import { Management } from '@/features/operations/management';
import { Staff } from '@/features/operations/staff';
import { Clinics, DoctorSchedule } from '@/features/patient/booking';
import {
  CompanionAccess,
  CompanionDashboard,
  CompanionInvite,
  CompanionList,
} from '@/features/patient/companion';
import { History } from '@/features/patient/history';
import { Account, Home } from '@/features/patient/home';
import { PatientData, PatientForm } from '@/features/patient/patient-data';
import { CheckIn, Visit } from '@/features/patient/visit';
import './types';

/**
 * Component names used by Laravel (Inertia::render / Route::inertia in routes/web.php).
 * This replaces the catch-all route switch of the previous Next.js app.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export const pages: Record<string, ComponentType<any>> = {
  Login,
  InternalLogin,
  Register,
  RegisterOtp,
  RegisterVerified,
  ErrorPage,
  Home,
  Account,
  Clinics,
  DoctorSchedule,
  Visit,
  CheckIn,
  History,
  CompanionAccess,
  CompanionInvite,
  CompanionList,
  CompanionDashboard,
  PatientData,
  PatientForm,
  Hya,
  Help,
  CheckInGuide,
  QueueGuide,
  Directions,
  Notifications,
  Staff,
  Management,
};
