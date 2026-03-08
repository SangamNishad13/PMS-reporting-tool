import { create } from 'zustand';
import { issuesApi } from '../services/issuesApi';

const useIssuesStore = create((set, get) => ({
  // State
  issues: [],
  loading: false,
  error: null,
  selectedIssueIds: [],
  currentIssue: null,
  isModalOpen: false,
  modalMode: 'add', // 'add' or 'edit'
  
  // Project context
  projectId: null,
  pageId: null,
  
  // Filters
  activeTab: 'final', // 'final' or 'needs_review'
  
  // Actions
  setProjectContext: (projectId, pageId) => {
    set({ projectId, pageId });
  },
  
  setActiveTab: (tab) => {
    set({ activeTab: tab });
  },
  
  loadIssues: async (projectId, pageId, filters = {}) => {
    set({ loading: true, error: null });
    try {
      const response = await issuesApi.getIssues(projectId, { page_id: pageId, ...filters });
      set({ 
        issues: response.issues || [], 
        loading: false 
      });
    } catch (error) {
      set({ 
        error: error.message || 'Failed to load issues', 
        loading: false 
      });
    }
  },
  
  // Update a single issue in the store without full reload
  updateSingleIssue: async (issueId, projectId) => {
    try {
      const response = await issuesApi.getIssue(issueId, projectId);
      const updatedIssue = response.issue;
      
      if (updatedIssue) {
        set((state) => ({
          issues: state.issues.map(issue => 
            issue.id === issueId ? updatedIssue : issue
          )
        }));
      }
    } catch (error) {
      // Silent fail
    }
  },
  
  // Add a new issue to the store without full reload
  addSingleIssue: async (issueId, projectId) => {
    try {
      const response = await issuesApi.getIssue(issueId, projectId);
      const newIssue = response.issue;
      
      if (newIssue) {
        set((state) => ({
          issues: [newIssue, ...state.issues]
        }));
      }
    } catch (error) {
      // Silent fail
    }
  },
  
  openAddModal: () => {
    set({ 
      isModalOpen: true, 
      modalMode: 'add', 
      currentIssue: null 
    });
  },
  
  openEditModal: async (issueId) => {
    const { issues } = get();
    const issue = issues.find(i => i.id === issueId);
    
    if (issue) {
      set({ 
        isModalOpen: true, 
        modalMode: 'edit', 
        currentIssue: issue,
      });
    } else {
      set({ 
        error: 'Issue not found', 
      });
    }
  },
  
  closeModal: () => {
    set({ 
      isModalOpen: false, 
      currentIssue: null 
    });
  },
  
  saveIssue: async (issueData) => {
    const { modalMode, currentIssue, projectId, pageId } = get();
    set({ loading: true, error: null });
    
    try {
      if (modalMode === 'edit' && currentIssue?.id) {
        await issuesApi.updateIssue(currentIssue.id, issueData);
      } else {
        await issuesApi.createIssue({ ...issueData, project_id: projectId, page_id: pageId });
      }
      
      // Reload issues
      await get().loadIssues(projectId, pageId);
      set({ isModalOpen: false, currentIssue: null, loading: false });
      return { success: true };
    } catch (error) {
      set({ 
        error: error.message || 'Failed to save issue', 
        loading: false 
      });
      return { success: false, error: error.message };
    }
  },
  
  deleteIssues: async (issueIds) => {
    const { projectId, pageId } = get();
    set({ loading: true, error: null });
    
    try {
      await issuesApi.deleteIssues(issueIds, projectId);
      await get().loadIssues(projectId, pageId);
      set({ selectedIssueIds: [], loading: false });
      return { success: true };
    } catch (error) {
      set({ 
        error: error.message || 'Failed to delete issues', 
        loading: false 
      });
      return { success: false, error: error.message };
    }
  },
  
  markClientReady: async (issueIds) => {
    const { projectId, pageId } = get();
    set({ loading: true, error: null });
    
    try {
      await issuesApi.markClientReady(issueIds);
      await get().loadIssues(projectId, pageId);
      set({ selectedIssueIds: [], loading: false });
      return { success: true };
    } catch (error) {
      set({ 
        error: error.message || 'Failed to mark client ready', 
        loading: false 
      });
      return { success: false, error: error.message };
    }
  },
  
  toggleSelectIssue: (issueId) => {
    const { selectedIssueIds } = get();
    if (selectedIssueIds.includes(issueId)) {
      set({ selectedIssueIds: selectedIssueIds.filter(id => id !== issueId) });
    } else {
      set({ selectedIssueIds: [...selectedIssueIds, issueId] });
    }
  },
  
  selectAllIssues: (issueIds) => {
    set({ selectedIssueIds: issueIds });
  },
  
  clearSelection: () => {
    set({ selectedIssueIds: [] });
  },
}));

export default useIssuesStore;
