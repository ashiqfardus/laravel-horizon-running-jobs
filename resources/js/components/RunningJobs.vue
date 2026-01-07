<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">
                    <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                    </svg>
                    Running Jobs
                </h5>
                <div class="d-flex align-items-center">
                    <label class="me-2 mb-0">
                        <input type="checkbox" v-model="showAll" @change="fetchJobs"> Show All Servers
                    </label>
                    <button class="btn btn-sm btn-outline-primary" @click="fetchJobs" :disabled="loading">
                        <span v-if="loading">Refreshing...</span>
                        <span v-else>Refresh</span>
                    </button>
                </div>
            </div>

            <div v-if="error" class="alert alert-danger m-3">
                {{ error }}
            </div>

            <div v-if="!loading && jobs.length === 0" class="card-body text-center text-muted">
                <p class="mb-0">No jobs currently running{{ showAll ? '' : ' on this server' }}</p>
            </div>

            <table v-if="jobs.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Queue</th>
                        <th>Server</th>
                        <th>Duration</th>
                        <th>Attempts</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="job in jobs" :key="job.job_id" :class="{ 'table-warning': job.running_for_seconds > 300 }">
                        <td>
                            <span class="badge bg-secondary me-1">{{ job.job_id.substring(0, 8) }}...</span>
                            {{ formatJobName(job.job_class) }}
                        </td>
                        <td>
                            <span class="badge bg-info">{{ job.queue }}</span>
                        </td>
                        <td>{{ job.server }}</td>
                        <td>
                            <span :class="{ 'text-danger fw-bold': job.running_for_seconds > 300 }">
                                {{ job.running_for_formatted }}
                            </span>
                        </td>
                        <td>{{ job.attempts }}</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="jobs.length > 0" class="card-footer text-muted">
                <small>
                    {{ jobs.length }} running job(s) |
                    Host: {{ hostname }} |
                    Last updated: {{ lastUpdated }}
                </small>
                <span v-if="warnings.length > 0" class="text-warning ms-2">
                    ⚠️ {{ warnings.join(', ') }}
                </span>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    data() {
        return {
            jobs: [],
            warnings: [],
            hostname: '',
            loading: false,
            error: null,
            showAll: false,
            lastUpdated: '',
            refreshInterval: null,
        };
    },

    mounted() {
        this.fetchJobs();
        // Auto-refresh every 5 seconds
        this.refreshInterval = setInterval(() => {
            this.fetchJobs();
        }, 5000);
    },

    beforeDestroy() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
    },

    methods: {
        async fetchJobs() {
            this.loading = true;
            this.error = null;

            try {
                const params = new URLSearchParams();
                if (this.showAll) {
                    params.append('all', 'true');
                }

                const response = await fetch(`/api/horizon/running-jobs?${params.toString()}`);

                if (!response.ok) {
                    throw new Error('Failed to fetch running jobs');
                }

                const data = await response.json();

                if (data.success) {
                    this.jobs = data.jobs || [];
                    this.warnings = data.warnings || [];
                    this.hostname = data.hostname;
                    this.lastUpdated = new Date().toLocaleTimeString();
                } else {
                    this.error = data.message || 'Failed to fetch running jobs';
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        formatJobName(className) {
            // Extract just the class name from full namespace
            const parts = className.split('\\');
            return parts[parts.length - 1];
        },
    },
};
</script>

<style scoped>
.icon {
    width: 1.25rem;
    height: 1.25rem;
    margin-right: 0.5rem;
}

.card {
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.table-warning {
    background-color: #fff3cd !important;
}
</style>

