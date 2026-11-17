import Core
import DesignSystem
import SwiftUI

/// Your calendar, on What Expadu knows (the calendar canvas's Knows board): its own switch, apart
/// from personalisation; turning it on shows the one-button Continue (Apple's HIG for a screen
/// before a permission alert), then iOS asks. On, it lists the calendars it reads. What's in them
/// stays on this phone; only when they're busy goes with Today's request, never kept.
struct CalendarSection: View {
    private var calendar: MyCalendar { MyCalendar.shared }
    @State private var switchOn = MyCalendar.shared.reading
    @State private var asking = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Your calendar").font(.posterGrotesque(19)).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
                .padding(.bottom, 8).padding(.horizontal, 4)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 14) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Use my calendar on Today").font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                        Text("Your day shows what’s in it, and ideas fit around it. Only when you’re busy leaves this phone, never what.")
                            .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: 4)
                    Toggle("Use my calendar on Today", isOn: $switchOn).labelsHidden()
                        .onChange(of: switchOn) { _, on in
                            guard on != calendar.reading else { return }
                            if on {
                                // iOS said no before: only Settings can change it.
                                if calendar.refused { switchOn = false; openSettings() } else { asking = true }
                            } else {
                                withAnimation(.snappy) { calendar.turnOff() }
                            }
                        }
                }
                .padding(.horizontal, 16).padding(.vertical, 14)

                if calendar.reading {
                    Divider().overlay(Palette.border)
                    Text("CALENDARS IT READS").font(.geist(12.5, .bold)).tracking(0.3).foregroundStyle(Palette.textSoft)
                        .padding(.horizontal, 16).padding(.top, 12).padding(.bottom, 2)
                    ForEach(calendar.sources) { source in
                        Button { withAnimation(.snappy) { calendar.toggle(source) } } label: {
                            HStack(spacing: 12) {
                                Circle().fill(source.color).frame(width: 12, height: 12)
                                Text(source.title).font(.geist(15.5)).foregroundStyle(Palette.text)
                                Spacer(minLength: 8)
                                Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(TodayLink.ink)
                                    .opacity(calendar.reads(source) ? 1 : 0)
                            }
                            .frame(minHeight: 48).padding(.horizontal, 16).contentShape(.rect)
                        }
                        .buttonStyle(PressStyle())
                        .accessibilityAddTraits(calendar.reads(source) ? [.isSelected] : [])
                    }
                    .transition(.opacity.combined(with: .move(edge: .top)))
                } else if calendar.refused {
                    Divider().overlay(Palette.border)
                    Button { openSettings() } label: {
                        Text("Calendar access is off for Expadu. Allow it in Settings › Expadu › Calendars.")
                            .font(.geist(13.5)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.leading)
                            .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 12)
                    }
                    .buttonStyle(PressStyle())
                }
            }
            .card(radius: 18)
            Text("Stays on this phone: titles, places, notes and who’s invited. Sent with each look at Today: when you’re busy today and tomorrow, as times only, never kept.")
                .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 4).padding(.top, 8)
        }
        .sheet(isPresented: $asking, onDismiss: { switchOn = calendar.reading }) {
            CalendarAskSheet { asking = false }
                .presentationDetents([.medium])
                .presentationBackground(Palette.canvas)
                .interactiveDismissDisabled()
        }
    }

    private func openSettings() {
        if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
    }
}

/// Before iOS asks: what it's for and what stays on the phone, and one button (Apple's HIG).
struct CalendarAskSheet: View {
    let done: () -> Void
    @State private var asking = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Image(systemName: "calendar").font(.system(size: 28, weight: .semibold)).foregroundStyle(Color(hex: 0x2F6FD6))
                .frame(width: 60, height: 60).background(Palette.surface, in: .rect(cornerRadius: 18))
                .overlay(RoundedRectangle(cornerRadius: 18).stroke(Palette.border))
                .accessibilityHidden(true)
            Text("Fit Today around your calendar")
                .font(.posterGrotesque(24)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                .padding(.top, 14).accessibilityAddTraits(.isHeader)
            Text("Your day shows what’s in your calendar, and ideas only come where you’re free. iPhone asks for full access, which is how calendars are read; Expadu never adds or changes anything.")
                .font(.geist(15)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true).padding(.top, 8)
            Text("What’s in it stays on this phone. Only when you’re busy is sent, and never kept.")
                .font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true).padding(.top, 6)
            Spacer(minLength: 12)
            Button {
                asking = true
                Task {
                    await MyCalendar.shared.turnOn()
                    done()
                }
            } label: {
                Text("Continue").font(.geist(16.5, .semibold)).foregroundStyle(Palette.onAction)
                    .frame(maxWidth: .infinity, minHeight: 52)
            }
            .buttonFace(Palette.action, radius: 18)
            .disabled(asking)
        }
        .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 12)
    }
}
