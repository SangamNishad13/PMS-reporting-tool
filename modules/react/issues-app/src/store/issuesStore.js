import { create } from 'zustand';
import issuesApi from '../services/issuesApi';

const useIssuesStore = create((set, get) => ({
  // State
  issues: [],
  selectedPageId: null,
  selectedIssue: null,
  issueStatuses: window.APP_CONFIG?.issueStatuses || [],
  projectPages: [],
  metadataFields: [],
  loading: false,
  error: null,
  viewMode: 'all', // 'all', 'pages', 'common'

  // Actions
  setViewMode: (mode) => set({ viewMode: mode }),
  
  setSelectedPageId: (pageId) => set({ selectedPageId: pageId }),
  
  setSelectedIssue: (issue) => set({ selectedIssue: issue }),

  fetchIssues: async (projectId, pageId = null) => {
    set({ loading: true, error: null });
    try {
      const viewMode = get().viewMode;
      const params = {
        action: 'list',
        project_id: projectId,
      };
      
      // Add page filter for pages view
      if (viewMode === 'pages' && pageId) {
        params.page_id = pageId;
      }
      
      // Add common filter for common issues view
      if (viewMode === 'common') {
        params.common_only = true;
      }
      
      const response = await issuesApi.getIssues(projectId, pageId);
      set({ 
        issues: response.issues || [],
        loading: false 
      });
    } catch (error) {
      set({ 
        error: error.message, 
        loading: false 
      });
    }
  },

  fetchIssueStatuses: async (projectId) => {
    // Statuses are already loaded from APP_CONFIG, but allow refresh if needed
    const currentStatuses = get().issueStatuses;
    if (currentStatuses && currentStatuses.length > 0) {
      return; // Already have statuses
    }
    
    try {
      const response = await issuesApi.getIssueStatuses(projectId);
      set({ issueStatuses: response.statuses || [] });
    } catch (error) {
      console.error('Failed to fetch statuses:', error);
      // Keep existing statuses from APP_CONFIG
    }
  },

  fetchProjectPages: async (projectId) => {
    try {
      const response = await issuesApi.getProjectPages(projectId);
      set({ projectPages: response.pages || [] });
    } catch (error) {
      console.error('Failed to fetch pages:', error);
    }
  },

  fetchMetadataFields: async (projectType) => {
    try {
      const response = await issuesApi.getMetadataOptions(projectType);
      set({ metadataFields: response.fields || [] });
    } catch (error) {
      console.error('Failed to fetch metadata:', error);
    }
  },

  createIssue: async (issueData) => {
    set({ loading: true, error: null });
    try {
      const response = await issuesApi.createIssue(issueData);
      
      // Optimistic update
      const newIssue = { ...issueData, id: response.id, issue_key: response.issue_key };
      set(state => ({ 
        issues: [newIssue, ...state.issues],
        loading: false 
      }));
      
      return response;
    } catch (error) {
      set({ error: error.message, loading: false });
      throw error;
    }
  },

  updateIssue: async (issueId, issueData) => {
    set({ loading: true, error: null });
    try {
      const response = await issuesApi.updateIssue(issueId, issueData);
      
      // Optimistic update
      set(state => ({
        issues: state.issues.map(issue => 
          issue.id === issueId ? { ...issue, ...issueData } : issue
        ),
        loading: false
      }));
      
      return response;
    } catch (error) {
      set({ error: error.message, loading: false });
      throw error;
    }
  },

  deleteIssue: async (issueId) => {
    set({ loading: true, error: null });
    try {
      await issuesApi.deleteIssue(issueId);
      
      // Optimistic update
      set(state => ({
        issues: state.issues.filter(issue => issue.id !== issueId),
        loading: false
      }));
    } catch (error) {
      set({ error: error.message, loading: false });
      throw error;
    }
  },
}));

export default useIssuesStore;
