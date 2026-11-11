#include <sys/types.h>
#include <sys/wait.h>
#include <unistd.h>
#include <signal.h>
#include <errno.h>
static volatile sig_atomic_t child_pid = -1;
static void forward(int sig) { if (child_pid > 0) kill((pid_t)child_pid, sig); }
int main(int argc, char **argv) {
    if (argc < 2) return 64;
    pid_t pid = fork();
    if (pid < 0) return 71;
    if (pid == 0) { execv(argv[1], &argv[1]); _exit(127); }
    child_pid = pid;
    struct sigaction action = {0};
    action.sa_handler = forward;
    sigemptyset(&action.sa_mask);
    sigaction(SIGTERM, &action, 0);
    sigaction(SIGINT, &action, 0);
    sigaction(SIGHUP, &action, 0);
    int status;
    while (waitpid(pid, &status, 0) < 0) { if (errno != EINTR) return 71; }
    if (WIFEXITED(status)) return WEXITSTATUS(status);
    if (WIFSIGNALED(status)) return 128 + WTERMSIG(status);
    return 70;
}
