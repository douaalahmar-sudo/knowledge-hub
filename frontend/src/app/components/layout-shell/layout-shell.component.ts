import { Component, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';


// Models & Interfaces
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
// Initial Mock Data
const MOCK_PROCEDURES: Procedure[] = [
{
    id: 1, code: 'PR-2026-001', title: 'Ouverture & Fermeture de Caisse', category: 'Caisse / Finance', version: 'v2.1', status: 'VALIDATED', kaizenCount: 2, updatedAt: '2026-07-15'
},
{
    id: 2, code: 'PR-2026-005', title: 'Réception & Contrôle Fournisseurs', category: 'Logistique', version: 'v1.4', status: 'VALIDATED', kaizenCount: 0, updatedAt: '2026-06-20'
},
{
    id: 3, code: 'PR-2026-008', title: 'Gestion des Retours & Avoir Client', category: 'Service Client', version: 'v3.0-DRAFT', status: 'DRAFT', kaizenCount: 1, updatedAt: '2026-07-21'
},
];
const MOCK_KAIZENS: KaizenSignal[] = [
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
@Component(
{
    selector: 'app-layout-shell',
    standalone: true,
imports: [CommonModule, RouterModule], templateUrl: './layout-shell.component.html',
    styleUrls: ['./layout-shell.component.scss']
})


export class LayoutShellComponent
{
    // Navigation & Tenant State
    activeNav = signal<'dashboard' | 'procedures' | 'kaizen' | 'personnel'>('dashboard');
    isRightDrawerOpen = signal<boolean>(true);
    currentTenant = signal<string>('FLESK Store #101 - Tunis');
    // Core Data Signals
    procedures = signal<Procedure[]>(MOCK_PROCEDURES);
    kaizens = signal<KaizenSignal[]>(MOCK_KAIZENS);
    selectedProcedureId = signal<number>(1);
    // Personnel State
    personnelList = signal<PersonnelUser[]>([
    {
        id: 1, name: 'Sami Ben Ali', email: 'sami.benali@flesk.tn', role: 'ADMIN', status: 'ACTIVE', lastActive: 'Aujourd’hui à 14:20'
    },
    {
        id: 2, name: 'Sarra Khelifi', email: 'sarra.khelifi@flesk.tn', role: 'MANAGER', status: 'ACTIVE', lastActive: 'Aujourd’hui à 10:15'
    },
    {
        id: 3, name: 'Kamel Triki', email: 'kamel.triki@flesk.tn', role: 'OPERATOR', status: 'ACTIVE', lastActive: 'Hier à 16:45'
    },
    {
        id: 4, name: 'Youssef Mansour', email: 'youssef.m@flesk.tn', role: 'OPERATOR', status: 'INACTIVE', lastActive: 'Il y a 5 jours'
    },
    ]);
    // Activity Log Signal
    activities = signal<ActivityLog[]>([
    {
        id: 1, type: 'KAIZEN', title: 'Nouvel écart critique sur Ouverture & Fermeture de Caisse', time: 'Il y a 10 min', user: 'Sarra Khelifi'
    },
    {
        id: 2, type: 'PROCEDURE', title: 'Mise à jour v3.0-DRAFT pour Inventaire Physique', time: 'Il y a 2 heures', user: 'Sami Ben Ali'
    },
    {
        id: 3, type: 'PROCEDURE', title: 'Validation conforme de la procédure PR-2026-008', time: 'Hier à 14:30', user: 'Directeur Général'
    },
    {
        id: 4, type: 'KAIZEN', title: 'Signalement résolu sur scanner d’inventaire', time: 'Hier à 09:15', user: 'Kamel Triki'
    }
    ]);
    // ==================== TASK 2.5: SEARCH & FILTER SIGNALS ====================
    searchQuery = signal<string>('');
    selectedCategoryFilter = signal<string>('ALL');
    selectedStatusFilter = signal<string>('ALL');
    selectedSeverityFilter = signal<string>('ALL');
    // Severity Ranking for Kaizen Sorting (Task 2.4.1)
    private severityRank: Record<'CRITICAL' | 'WARNING' | 'MINOR', number> =
    {
        CRITICAL: 1,
        WARNING: 2,
        MINOR: 3
    };
    // --- Modal States ---
    // 1. Kaizen Modal State
    isKaizenModalOpen = signal<boolean>(false);
    newKaizenDesc = signal<string>('');
    newKaizenSeverity = signal<'CRITICAL' | 'WARNING' | 'MINOR'>('WARNING');
    newKaizenProcId = signal<number>(1);
    newKaizenReporter = signal<string>('Sami Ben Ali');
    // 2. Invite Member Modal State
    isInviteModalOpen = signal<boolean>(false);
    inviteName = signal<string>('');
    inviteEmail = signal<string>('');
    inviteRole = signal<'ADMIN' | 'MANAGER' | 'OPERATOR'>('OPERATOR');
    // 3. New Procedure Modal State
    isProcedureModalOpen = signal<boolean>(false);
    newProcCode = signal<string>('');
    newProcTitle = signal<string>('');
    newProcCategory = signal<string>('Caisse / Finance');
    // ==================== COMPUTED PROPERTIES ====================
    selectedProcedure = computed(() =>
    this.procedures().find(p => p.id === this.selectedProcedureId())
    );
    activeKaizens = computed(() =>
    this.kaizens().filter(k => k.procedureId === this.selectedProcedureId())
    );
    totalProcedures = computed(() => this.procedures().length);
    validatedCount = computed(() => this.procedures().filter(p => p.status === 'VALIDATED').length);
    draftCount = computed(() => this.procedures().filter(p => p.status === 'DRAFT').length);
    complianceRate = computed(() => Math.round((this.validatedCount() / (this.totalProcedures() || 1)) * 100));
    activeKaizenCount = computed(() => this.kaizens().length);
    activeSignalsCount = computed(() => this.activeKaizenCount());
    criticalKaizenCount = computed(() => this.kaizens().filter(k => k.severity === 'CRITICAL').length);
    // --- Task 2.4.1: Sorted Kaizens by Severity Rank & Date ---
    sortedKaizens = computed(() =>
    {
        return [...this.kaizens()].sort((a, b) => {
            
            const rankDiff = this.severityRank[a.severity] - this.severityRank[b.severity];
            if (rankDiff !== 0) return rankDiff;
            return new Date(b.date).getTime() - new Date(a.date).getTime();
        });
    });
    // --- Task 2.5.1 & 2.5.2: Reactive Computed Filter for Procedures ---
    filteredProcedures = computed(() =>
    {
        const q = this.searchQuery().toLowerCase().trim();
        const category = this.selectedCategoryFilter();
        const status = this.selectedStatusFilter();
        return this.procedures().filter(p => {
            
            const matchesText = !q || p.code.toLowerCase().includes(q) || p.title.toLowerCase().includes(q) || p.category.toLowerCase().includes(q);
            const matchesCategory = category === 'ALL' || p.category === category;
            const matchesStatus = status === 'ALL' || p.status === status;
            return matchesText && matchesCategory && matchesStatus;
        });
    });
    // --- Task 2.5.1 & 2.5.2: Reactive Computed Filter for Kaizens ---
    filteredKaizens = computed(() =>
    {
        const q = this.searchQuery().toLowerCase().trim();
        const severity = this.selectedSeverityFilter();
        return this.sortedKaizens().filter(k => {
            
            const matchesText = !q || k.description.toLowerCase().includes(q) || k.reporter.toLowerCase().includes(q);
            const matchesSeverity = severity === 'ALL' || k.severity === severity;
            return matchesText && matchesSeverity;
        });
    });
    // Check if any filter is active
    hasActiveFilters = computed(() =>
    {
        return this.searchQuery().trim() !== '' ||
        this.selectedCategoryFilter() !== 'ALL' ||
        this.selectedStatusFilter() !== 'ALL' ||
        this.selectedSeverityFilter() !== 'ALL';
    });
    // --- Reset All Filters ---
    resetAllFilters()
    {
        this.searchQuery.set('');
        this.selectedCategoryFilter.set('ALL');
        this.selectedStatusFilter.set('ALL');
        this.selectedSeverityFilter.set('ALL');
    }
    // --- Layout Actions ---
    selectProcedure(proc: Procedure)
    {
        this.selectedProcedureId.set(proc.id);
        this.isRightDrawerOpen.set(true);
    }
    setActiveNav(nav: 'dashboard' | 'procedures' | 'kaizen' | 'personnel')
    {
        this.activeNav.set(nav);
    }


    toggleRightDrawer()
    {
        this.isRightDrawerOpen.update(val => !val);
    }
    // --- Modal Control Actions ---
    // 1. Kaizen Modal Actions
    openKaizenModal(procId?: number)
    {
        if (procId)
        {
            this.newKaizenProcId.set(procId);
        }
        this.isKaizenModalOpen.set(true);
    }


    closeKaizenModal()
    {
        this.isKaizenModalOpen.set(false);
        this.newKaizenDesc.set('');
    }


    submitKaizen()
    {
        if (!this.newKaizenDesc().trim()) return;
        const targetProcId = Number(this.newKaizenProcId());
        const newSignal: KaizenSignal =
        {
            id: Date.now(),
            procedureId: targetProcId,
            severity: this.newKaizenSeverity(),
            description: this.newKaizenDesc(),
            reporter: this.newKaizenReporter().trim() || 'Anonyme',
            date: new Date().toISOString().split('T')[0]
        };
        this.kaizens.update(list => [newSignal, ...list]);
        this.procedures.update(procs =>
        procs.map(p => p.id === targetProcId ?
        {
            ...p, kaizenCount: p.kaizenCount + 1
        }
        : p)
        );
        this.activities.update(acts => [
        {
            id: Date.now(),
            type: 'KAIZEN',
            title: `Écart ${newSignal.severity}: ${newSignal.description.slice(0, 30)}...`,
            time: 'À l’instant',
            user: newSignal.reporter
        },
        ...acts
        ]);
        this.closeKaizenModal();
    }
    // 2. Invite Member Actions
    openInviteModal()
    {
        this.isInviteModalOpen.set(true);
    }


    closeInviteModal()
    {
        this.isInviteModalOpen.set(false);
        this.inviteName.set('');
        this.inviteEmail.set('');
        this.inviteRole.set('OPERATOR');
    }


    submitInvite()
    {
        if (!this.inviteName().trim() || !this.inviteEmail().trim()) return;
        const newUser: PersonnelUser =
        {
            id: Date.now(),
            name: this.inviteName().trim(),
            email: this.inviteEmail().trim(),
            role: this.inviteRole(),
            status: 'ACTIVE',
            lastActive: 'À l’instant'
        };
        this.personnelList.update(list => [newUser, ...list]);
        this.activities.update(acts => [
        {
            id: Date.now(),
            type: 'USER',
            title: `Nouveau membre invité: ${newUser.name} (${newUser.role})`,
            time: 'À l’instant',
            user: 'Sami Ben Ali'
        },
        ...acts
        ]);
        this.closeInviteModal();
    }
    // 3. New Procedure Actions
    openProcedureModal()
    {
        const nextId = this.procedures().length + 1;
        this.newProcCode.set(`PR-2026-0${nextId < 10 ? '0' + nextId : nextId}`);
        this.isProcedureModalOpen.set(true);
    }


    closeProcedureModal()
    {
        this.isProcedureModalOpen.set(false);
        this.newProcTitle.set('');
    }


    submitProcedure()
    {
        if (!this.newProcTitle().trim() || !this.newProcCode().trim()) return;
        const newProc: Procedure =
        {
            id: Date.now(),
            code: this.newProcCode().trim(),
            title: this.newProcTitle().trim(),
            category: this.newProcCategory(),
            version: 'v1.0-DRAFT',
            status: 'DRAFT',
            kaizenCount: 0,
            updatedAt: new Date().toISOString().split('T')[0]
        };
        this.procedures.update(list => [newProc, ...list]);
        this.selectedProcedureId.set(newProc.id);
        this.activities.update(acts => [
        {
            id: Date.now(),
            type: 'PROCEDURE',
            title: `Création de la procédure ${newProc.code} (${newProc.title})`,
            time: 'À l’instant',
            user: 'Sami Ben Ali'
        },
        ...acts
        ]);
        this.closeProcedureModal();
    }
}