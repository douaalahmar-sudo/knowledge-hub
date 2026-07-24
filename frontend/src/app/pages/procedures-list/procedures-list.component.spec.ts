import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ProceduresListComponent } from './procedures-list.component';

describe('ProceduresListComponent', () => {
  let component: ProceduresListComponent;
  let fixture: ComponentFixture<ProceduresListComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProceduresListComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(ProceduresListComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
