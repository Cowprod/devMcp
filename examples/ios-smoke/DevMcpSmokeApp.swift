import SwiftUI

@main
struct DevMcpSmokeApp: App {
    var body: some Scene {
        WindowGroup {
            VStack(spacing: 20) {
                Image(systemName: "checkmark.seal.fill")
                    .font(.system(size: 72)).foregroundStyle(.green)
                Text("devMcp sur iOS").font(.largeTitle.bold())
                Text("Compilé sur le Mac mini").foregroundStyle(.secondary)
                Text("Validation de la compilation et de la distribution depuis le lab.")
                    .multilineTextAlignment(.center)
            }.padding(32)
        }
    }
}
