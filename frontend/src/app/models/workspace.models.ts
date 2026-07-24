export interface Procedure
{
    id: number;
    code: string;
    title: string;
    category: string;
    version: string;
    status: 'VALIDATED' | 'DRAFT' | 'ARCHIVED';
    kaizenCount: number;
    updatedAt: string;
}


export interface KaizenSignal
{
    id: number;
    procedureId: number;
    severity: 'CRITICAL' | 'WARNING' | 'MINOR';
    description: string;
    reporter: string;
    date: string;
}


export interface PersonnelUser
{
    id: number;
    name: string;
    email: string;
    role: 'ADMIN' | 'MANAGER' | 'OPERATOR';
    status: 'ACTIVE' | 'INACTIVE';
    lastActive: string;
}


export interface ActivityLog
{
    id: number;
    type: 'PROCEDURE' | 'KAIZEN' | 'USER';
    title: string;
    time: string;
    user: string;
}
// Initial Mock Data Fallbacks
export const MOCK_PROCEDURES: Procedure[] = [
{
    id: 1, code: 'PR-2026-001', title: 'Ouverture & Fermeture de Caisse', category: 'Caisse / Finance', version: 'v2.1', status: 'VALIDATED', kaizenCount: 2, updatedAt: '2026-07-15'
},
{
    id: 2, code: 'PR-2026-005', title: 'Réception & Contrôle Fournisseurs', category: 'Logistique', version: 'v1.4', status: 'VALIDATED', kaizenCount: 0, updatedAt: '2026-06-20'
},
{
    id: 3, code: 'PR-2026-008', title: 'Gestion des Retours & Aavoirs Clients', category: 'Service Client', version: 'v3.0-DRAFT', status: 'DRAFT', kaizenCount: 1, updatedAt: '2026-07-21'
},
];


export const MOCK_KAIZENS: KaizenSignal[] = [
{
    id: 101, procedureId: 1, severity: 'CRITICAL', description: 'Scanner de code-barres défectueux en caisse #2 lors du rush de midi.', reporter: 'Sarra Khelifi', date: '2026-07-22'
},
{
    id: 102, procedureId: 1, severity: 'WARNING', description: 'Procédure papier illisible affichée sur le poste caisse.', reporter: 'Kamel Triki', date: '2026-07-20'
},
{
    id: 103, procedureId: 3, severity: 'MINOR', description: 'Bouton de validation du formulaire de retour manque de clarification.', reporter: 'Sami Ben Ali', date: '2026-07-18'
}
];