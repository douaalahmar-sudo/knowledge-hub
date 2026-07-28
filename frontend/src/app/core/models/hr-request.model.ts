export type HrRequestType =
  | 'PAYSLIP'          // Fiche de paie
  | 'WORK_CERTIFICATE' // Attestation de travail
  | 'LEAVE_REQUEST'    // Demande de congé
  | 'CUSTOM';          // Autre demande administrative

export type HrRequestStatus =
  | 'PENDING'             // En attente
  | 'IN_PROGRESS'         // En cours
  | 'APPROVED'            // Approuvé
  | 'REJECTED'            // Refusé
  | 'READY_FOR_DOWNLOAD'; // Prêt à télécharger

export interface HrRequest {
  id?: string | number;
  user_id?: number;
  user_name?: string;
  tenant?: string;
  type: HrRequestType;
  title: string;
  description?: string | null;
  start_date?: string | null;
  end_date?: string | null;
  attachments?: string[];
  status: HrRequestStatus;
  admin_note?: string | null;
  pdf_url?: string | null;
  created_at?: string;
  updated_at?: string;
}

/** Presentation metadata for each request type (label, icon name, helper text). */
export const HR_TYPE_META: Record<HrRequestType, { label: string; icon: string; description: string }> = {
  PAYSLIP:          { label: 'Fiche de paie',          icon: 'file',     description: 'Bulletin de salaire mensuel' },
  WORK_CERTIFICATE: { label: 'Attestation de travail', icon: 'policy',   description: 'Justificatif d’emploi officiel' },
  LEAVE_REQUEST:    { label: 'Demande de congé',   icon: 'calendar', description: 'Absence / congés payés' },
  CUSTOM:           { label: 'Autre demande',          icon: 'inbox',    description: 'Demande administrative libre' },
};

/** Color-coded status metadata (Tailwind classes) — replaces the emoji dots. */
export const HR_STATUS_META: Record<HrRequestStatus, { label: string; badgeClass: string; dotClass: string }> = {
  PENDING:            { label: 'En attente',          badgeClass: 'bg-amber-100 text-amber-800', dotClass: 'bg-amber-500' },
  IN_PROGRESS:        { label: 'En cours',            badgeClass: 'bg-blue-100 text-blue-800',   dotClass: 'bg-blue-500' },
  APPROVED:           { label: 'Approuvé',        badgeClass: 'bg-green-100 text-green-800', dotClass: 'bg-green-500' },
  READY_FOR_DOWNLOAD: { label: 'Prêt à télécharger', badgeClass: 'bg-green-100 text-green-800', dotClass: 'bg-green-500' },
  REJECTED:           { label: 'Refusé',          badgeClass: 'bg-red-100 text-red-800',     dotClass: 'bg-red-500' },
};

/** Leave sub-types offered in the LEAVE_REQUEST form. */
export const LEAVE_TYPES = [
  'Congé payé',
  'Congé maladie',
  'Congé sans solde',
  'Congé exceptionnel',
] as const;
