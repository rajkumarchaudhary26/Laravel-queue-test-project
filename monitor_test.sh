#!/bin/bash

# Simple resource monitoring script for queue performance tests
# Usage: ./monitor_test.sh redis_3jobs
#   This will create: redis_3jobs_resources.txt

TEST_NAME=${1:-test}
OUTPUT_FILE="${TEST_NAME}_resources.txt"
INTERVAL=5  # Sample every 5 seconds

echo "========================================" | tee "$OUTPUT_FILE"
echo "Resource Monitoring: $TEST_NAME" | tee -a "$OUTPUT_FILE"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S')" | tee -a "$OUTPUT_FILE"
echo "========================================" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"

# Function to get current stats
get_stats() {
    local timestamp=$(date '+%H:%M:%S')
    
    echo "[$timestamp]" | tee -a "$OUTPUT_FILE"
    
    # Docker container stats
    docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}" \
        queue-php queue-redis queue-db | tee -a "$OUTPUT_FILE"
    
    echo "" | tee -a "$OUTPUT_FILE"
}

# Get baseline
echo "=== BASELINE (before test) ===" | tee -a "$OUTPUT_FILE"
get_stats
echo "" | tee -a "$OUTPUT_FILE"

echo "Monitoring started. Press Ctrl+C to stop and see summary."
echo "Sampling every $INTERVAL seconds..."
echo ""

# Track peak values
declare -A peak_cpu
declare -A peak_mem

# Monitor loop
sample_count=0
trap 'echo ""; echo "Monitoring stopped. Generating summary..."; break' INT

while true; do
    sleep $INTERVAL
    ((sample_count++))
    
    echo "=== Sample #$sample_count ===" | tee -a "$OUTPUT_FILE"
    get_stats
    
    # Extract peak values (simple parsing)
    while IFS= read -r line; do
        if [[ $line =~ queue-php.*([0-9.]+)%.*([0-9.]+MiB) ]]; then
            cpu="${BASH_REMATCH[1]}"
            mem="${BASH_REMATCH[2]}"
            if (( $(echo "$cpu > ${peak_cpu[php]:-0}" | bc -l) )); then
                peak_cpu[php]=$cpu
            fi
            if [[ $mem =~ ([0-9.]+) ]]; then
                mem_val="${BASH_REMATCH[1]}"
                if (( $(echo "$mem_val > ${peak_mem[php]:-0}" | bc -l) )); then
                    peak_mem[php]=$mem_val
                fi
            fi
        fi
        if [[ $line =~ queue-redis.*([0-9.]+)%.*([0-9.]+MiB) ]]; then
            cpu="${BASH_REMATCH[1]}"
            mem="${BASH_REMATCH[2]}"
            if (( $(echo "$cpu > ${peak_cpu[redis]:-0}" | bc -l) )); then
                peak_cpu[redis]=$cpu
            fi
            if [[ $mem =~ ([0-9.]+) ]]; then
                mem_val="${BASH_REMATCH[1]}"
                if (( $(echo "$mem_val > ${peak_mem[redis]:-0}" | bc -l) )); then
                    peak_mem[redis]=$mem_val
                fi
            fi
        fi
        if [[ $line =~ queue-db.*([0-9.]+)%.*([0-9.]+MiB) ]]; then
            cpu="${BASH_REMATCH[1]}"
            mem="${BASH_REMATCH[2]}"
            if (( $(echo "$cpu > ${peak_cpu[db]:-0}" | bc -l) )); then
                peak_cpu[db]=$cpu
            fi
            if [[ $mem =~ ([0-9.]+) ]]; then
                mem_val="${BASH_REMATCH[1]}"
                if (( $(echo "$mem_val > ${peak_mem[db]:-0}" | bc -l) )); then
                    peak_mem[db]=$mem_val
                fi
            fi
        fi
    done < <(docker stats --no-stream queue-php queue-redis queue-db)
done

# Summary
echo "" | tee -a "$OUTPUT_FILE"
echo "========================================" | tee -a "$OUTPUT_FILE"
echo "SUMMARY - Peak Resource Usage" | tee -a "$OUTPUT_FILE"
echo "========================================" | tee -a "$OUTPUT_FILE"
echo "Total Samples: $sample_count" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"
echo "queue-php:" | tee -a "$OUTPUT_FILE"
echo "  Peak CPU: ${peak_cpu[php]:-N/A}%" | tee -a "$OUTPUT_FILE"
echo "  Peak Memory: ${peak_mem[php]:-N/A} MiB" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"
echo "queue-redis:" | tee -a "$OUTPUT_FILE"
echo "  Peak CPU: ${peak_cpu[redis]:-N/A}%" | tee -a "$OUTPUT_FILE"
echo "  Peak Memory: ${peak_mem[redis]:-N/A} MiB" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"
echo "queue-db:" | tee -a "$OUTPUT_FILE"
echo "  Peak CPU: ${peak_cpu[db]:-N/A}%" | tee -a "$OUTPUT_FILE"
echo "  Peak Memory: ${peak_mem[db]:-N/A} MiB" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"

# Get Redis memory details
echo "========================================" | tee -a "$OUTPUT_FILE"
echo "Redis Memory Details" | tee -a "$OUTPUT_FILE"
echo "========================================" | tee -a "$OUTPUT_FILE"
docker exec queue-redis redis-cli INFO memory | grep -E "used_memory_human|used_memory_peak_human|mem_fragmentation" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"

# Get PostgreSQL details
echo "========================================" | tee -a "$OUTPUT_FILE"
echo "PostgreSQL Details" | tee -a "$OUTPUT_FILE"
echo "========================================" | tee -a "$OUTPUT_FILE"
docker exec queue-db psql -U postgres -d queuework -c "
SELECT 
    pg_size_pretty(pg_database_size('queuework')) as database_size,
    (SELECT count(*) FROM jobs) as jobs_in_queue,
    (SELECT count(*) FROM failed_jobs) as failed_jobs;
" | tee -a "$OUTPUT_FILE"

echo "" | tee -a "$OUTPUT_FILE"
echo "Monitoring complete. Results saved to: $OUTPUT_FILE" | tee -a "$OUTPUT_FILE"
echo "Ended: $(date '+%Y-%m-%d %H:%M:%S')" | tee -a "$OUTPUT_FILE"