import { create } from 'zustand';
import issuesApi from '../services/issuesApi';

const useIssuesStore = create((set, get) => ({
  // State
  issues: [],
  selectedPageId: null,
  selectedIssue: null,
  issueStatuses: [],
  projectPages: [],
  metadataFields: [],
  loading: false,
  error: null,

  // Actions
  setSelectedPageId: (pageId) => set({ selectedPageId: pageId }),
  
  setSelectedIssue: (issue) => set({ selectedIssue: issue }),

  fetchIssues: async (projectId, pageId = null) => {
    set({ loading: true, error: null });
    try {
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

  fetchIssueStatuses: async () => {
    try {
      const response = await issuesApi.getIssueStatuses();
      set({ issueStatuses: response.statuses || [] });
    } catch (error) {
      console.error('Failed to fetch statuses:', error);
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
